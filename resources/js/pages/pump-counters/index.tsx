import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { EntryStamp } from '@/components/pump-counters/last-entry';
import {
    PreviousReading,
    SystemLastEntry,
} from '@/components/pump-counters/last-entry';
import { SectionToolbar } from '@/components/section-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { selectableTanks } from '@/lib/tanks';
import { cn } from '@/lib/utils';
import {
    destroy,
    edit,
    exportPdf,
    exportXlsx,
    index,
    storeBulk,
} from '@/routes/pump-counters';
import type { Auth, PumpCounterReading, PumpSummary } from '@/types';

type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    is_active: boolean;
};

type FuelTypeTotal = {
    fuel_type_id: number;
    fuel_type_name: string;
    liters_sold: number;
};

/**
 * The reading's actual (possibly edited) date, combined with the time-of-day it was first
 * logged — reading.date alone has no time component, and reading.created_at alone doesn't
 * reflect a later date correction, so neither field on its own is right for this column.
 */
function loggedAt(reading: PumpCounterReading): string {
    return `${reading.date.slice(0, 10)}T${reading.created_at.slice(11)}`;
}

type PageProps = {
    auth: Auth;
    pumps: PumpSummary[];
    lastEntry: EntryStamp | null;
    tanks: TankOption[];
    readings: PumpCounterReading[];
    fuelTypeTotals: FuelTypeTotal[];
    governmentalTotals: FuelTypeTotal[];
    filters: { from: string; to: string };
};

// Defaults to whichever tank was used for this pump's last reading — attendants almost
// always feed the same pump from the same tank, so this saves a re-selection every time.
// Falls back to the first tank that actually matches the pump's fuel type(s), never to an
// arbitrary tank that might belong to a different fuel type.
function defaultTankFor(pump: PumpSummary | undefined, options: TankOption[]) {
    const lastTankId = pump?.latest_reading?.tank_id;
    const lastTankStillValid =
        lastTankId !== null &&
        lastTankId !== undefined &&
        options.some((tank) => tank.id === lastTankId);

    return lastTankStillValid ? lastTankId : (options[0]?.id ?? '');
}

// Tanks matching the pump's configured fuel type(s). If none match (e.g. the pump's fuel
// type has no tank yet, a configuration gap), falls back to every tank rather than leaving
// the select with nothing to choose from at all. Inactive tanks are never offered here --
// this only ever builds options for a *new* reading, never an existing one to preserve.
function tanksFor(
    pump: PumpSummary | undefined,
    tanks: TankOption[],
): TankOption[] {
    const active = selectableTanks(tanks);

    if (!pump || pump.fuel_type_ids.length === 0) {
        return active;
    }

    const matching = active.filter((tank) =>
        pump.fuel_type_ids.includes(tank.fuel_type_id),
    );

    return matching.length > 0 ? matching : active;
}

const TOTAL_BOX =
    'flex items-center justify-start gap-3 rounded-xl border bg-card px-4 py-2.5 dark:border-slate-700/60 dark:bg-slate-800/80';
const TOTAL_LABEL =
    'text-sm font-medium text-muted-foreground dark:text-slate-400';
const TOTAL_VALUE = 'text-xl font-extrabold md:text-2xl';

type BulkRow = {
    pump_id: number;
    tank_id: string;
    reading_value: string;
    governmental_liters: string;
    return_liters: string;
    notes: string;
};

function buildRows(pumpList: PumpSummary[], tanks: TankOption[]): BulkRow[] {
    return pumpList.map((pump) => ({
        pump_id: pump.id,
        tank_id: String(defaultTankFor(pump, tanksFor(pump, tanks))),
        reading_value: '',
        governmental_liters: '',
        return_liters: '',
        notes: '',
    }));
}

export default function PumpCountersIndex() {
    const {
        auth,
        pumps,
        lastEntry,
        tanks,
        readings,
        fuelTypeTotals,
        governmentalTotals,
        filters,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const defaultEntryDate = useDefaultEntryDate();

    // Colored accent per fuel type, keyed by order of first appearance among the pumps -- the
    // first fuel type (typically petrol) gets amber, the second (typically diesel) gets blue,
    // matching the same convention used on the Inventory page's tank cards. Shared by the bulk
    // entry table's per-row accent and the pump summary cards' top border below.
    const fuelTypeOrder: number[] = [];
    pumps.forEach((pump) => {
        const fuelTypeId = pump.fuel_type_ids[0];

        if (fuelTypeId !== undefined && !fuelTypeOrder.includes(fuelTypeId)) {
            fuelTypeOrder.push(fuelTypeId);
        }
    });

    const CARD_ACCENT_BORDERS = ['border-t-amber-500', 'border-t-blue-500'];
    const ROW_ACCENT_BORDERS = ['border-s-amber-500', 'border-s-blue-500'];
    const TEXT_ACCENTS = [
        'text-amber-600 dark:text-amber-400',
        'text-blue-600 dark:text-blue-400',
    ];
    const fuelTypeCardBorder: Record<number, string> = {};
    const fuelTypeRowBorder: Record<number, string> = {};
    const fuelTypeText: Record<number, string> = {};
    fuelTypeOrder.forEach((fuelTypeId, i) => {
        fuelTypeCardBorder[fuelTypeId] =
            CARD_ACCENT_BORDERS[i] ?? 'border-t-border';
        fuelTypeText[fuelTypeId] = TEXT_ACCENTS[i] ?? 'text-foreground';
        fuelTypeRowBorder[fuelTypeId] =
            ROW_ACCENT_BORDERS[i] ?? 'border-s-border';
    });

    // Pumps grouped by fuel type (stable sort keeps each fuel type's own pumps in their
    // original relative order) so the bulk entry table naturally clusters petrol pumps together
    // and diesel pumps together, without needing separate tables per fuel type.
    const sortedPumps = [...pumps].sort(
        (a, b) =>
            fuelTypeOrder.indexOf(a.fuel_type_ids[0]) -
            fuelTypeOrder.indexOf(b.fuel_type_ids[0]),
    );

    const pumpsById = useMemo(
        () => new Map(pumps.map((pump) => [pump.id, pump])),
        [pumps],
    );

    const form = useForm({
        date: defaultEntryDate,
        readings: buildRows(sortedPumps, tanks),
    });

    function updateRow(pumpId: number, patch: Partial<BulkRow>) {
        form.setData(
            'readings',
            form.data.readings.map((row) =>
                row.pump_id === pumpId ? { ...row, ...patch } : row,
            ),
        );
    }

    function submitAll(event: FormEvent) {
        event.preventDefault();
        form.post(storeBulk.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData(
                    'readings',
                    form.data.readings.map((row) => ({
                        ...row,
                        reading_value: '',
                        governmental_liters: '',
                        return_liters: '',
                        notes: '',
                    })),
                );
            },
        });
    }

    const exportFuelTypes = useMemo(() => {
        const seen = new Map<number, string>();

        for (const tank of tanks) {
            if (!seen.has(tank.fuel_type_id)) {
                seen.set(tank.fuel_type_id, tank.fuel_type_name);
            }
        }

        return Array.from(seen, ([id, name]) => ({ id, name }));
    }, [tanks]);

    function handleRangeChange(range: { from: string; to: string }) {
        // preserveState keeps the in-progress bulk entry table (whatever counter values are
        // already typed) intact — without it, Inertia remounts the page and every row quietly
        // resets back to blank.
        router.get(index(), range, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    const isSingleDay = filters.from === filters.to;
    const governmentalLiters = governmentalTotals.reduce(
        (sum, total) => sum + total.liters_sold,
        0,
    );

    function removeReading(reading: PumpCounterReading) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(reading.id));
        }
    }

    return (
        <>
            <Head title={t('pump_counters.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('pump_counters.title')}
                    description={t('pump_counters.description')}
                />

                <Card className="py-4">
                    <CardContent className="px-4">
                        <form onSubmit={submitAll} className="space-y-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <Label htmlFor="date">{t('common.date')}</Label>
                                <Input
                                    id="date"
                                    type="date"
                                    className="h-9 w-auto"
                                    value={form.data.date}
                                    onChange={(e) =>
                                        form.setData('date', e.target.value)
                                    }
                                />
                                <SystemLastEntry entry={lastEntry} />
                                <InputError message={form.errors.date} />
                            </div>

                            <InputError message={form.errors.readings} />

                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-4 text-center align-top">
                                                {t('pump_counters.pump')}
                                            </th>
                                            <th className="px-4 py-4 text-center align-top">
                                                {t('common.tank')}
                                            </th>
                                            <th className="px-4 py-4 text-center align-top">
                                                {t(
                                                    'pump_counters.reading_value',
                                                )}
                                            </th>
                                            <th className="px-4 py-4 text-center align-top">
                                                {t(
                                                    'pump_counters.governmental_sale',
                                                )}
                                            </th>
                                            <th className="px-4 py-4 text-center align-top">
                                                {t(
                                                    'pump_counters.return_liters',
                                                )}
                                            </th>
                                            <th className="px-4 py-4 text-center align-top">
                                                {t('common.notes')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {form.data.readings.map(
                                            (row, rowIndex) => {
                                                const pump = pumpsById.get(
                                                    row.pump_id,
                                                );

                                                if (!pump) {
                                                    return null;
                                                }

                                                const tankOptions = tanksFor(
                                                    pump,
                                                    tanks,
                                                );
                                                const maxLitersSold =
                                                    pump.latest_reading &&
                                                    row.reading_value !== ''
                                                        ? Number(
                                                              row.reading_value,
                                                          ) -
                                                          Number(
                                                              pump
                                                                  .latest_reading
                                                                  .reading_value,
                                                          )
                                                        : null;

                                                return (
                                                    <tr
                                                        key={pump.id}
                                                        className={cn(
                                                            'border-s-4 border-t',
                                                            fuelTypeRowBorder[
                                                                pump
                                                                    .fuel_type_ids[0]
                                                            ] ??
                                                                'border-s-border',
                                                        )}
                                                    >
                                                        <td className="px-4 py-4 align-top">
                                                            <div className="flex min-h-9 items-center justify-center gap-1.5 whitespace-nowrap text-base font-semibold">
                                                                {pump.name}
                                                                {pump.fuel_type_names.map(
                                                                    (name) => (
                                                                        <Badge
                                                                            key={
                                                                                name
                                                                            }
                                                                            variant="secondary"
                                                                            className="text-sm"
                                                                        >
                                                                            {
                                                                                name
                                                                            }
                                                                        </Badge>
                                                                    ),
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 align-top">
                                                            <Select
                                                                value={
                                                                    row.tank_id
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateRow(
                                                                        pump.id,
                                                                        {
                                                                            tank_id:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger className="mx-auto w-48 max-w-full dark:border-slate-600 dark:bg-slate-800/80">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {tankOptions.map(
                                                                        (
                                                                            tank,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    tank.id
                                                                                }
                                                                                value={String(
                                                                                    tank.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    tank.fuel_type_name
                                                                                }{' '}
                                                                                —{' '}
                                                                                {
                                                                                    tank.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                            <InputError
                                                                message={
                                                                    form.errors[
                                                                        `readings.${rowIndex}.tank_id`
                                                                    ]
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-4 py-4 align-top">
                                                            <div className="mx-auto w-48 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="1"
                                                                    min="0"
                                                                    className="w-full dark:border-slate-600 dark:bg-slate-800/80"
                                                                    value={
                                                                        row.reading_value
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateRow(
                                                                            pump.id,
                                                                            {
                                                                                reading_value:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                {pump.latest_reading && (
                                                                    <PreviousReading
                                                                        value={
                                                                            pump
                                                                                .latest_reading
                                                                                .reading_value
                                                                        }
                                                                        entry={
                                                                            pump.latest_reading
                                                                        }
                                                                    />
                                                                )}
                                                                <InputError
                                                                    message={
                                                                        form
                                                                            .errors[
                                                                            `readings.${rowIndex}.reading_value`
                                                                        ]
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 align-top">
                                                            <div className="mx-auto w-32 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    className="w-full dark:border-slate-600 dark:bg-slate-800/80"
                                                                    value={
                                                                        row.governmental_liters
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateRow(
                                                                            pump.id,
                                                                            {
                                                                                governmental_liters:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                {maxLitersSold !==
                                                                    null && (
                                                                    <p className="mt-1 whitespace-nowrap text-sm font-medium text-gray-600 dark:text-gray-300">
                                                                        {t(
                                                                            'pump_counters.max',
                                                                        )}
                                                                        :{' '}
                                                                        {formatNumber(
                                                                            maxLitersSold,
                                                                        )}{' '}
                                                                        L
                                                                    </p>
                                                                )}
                                                                <InputError
                                                                    message={
                                                                        form
                                                                            .errors[
                                                                            `readings.${rowIndex}.governmental_liters`
                                                                        ]
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 align-top">
                                                            <div className="mx-auto w-32 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    className="w-full dark:border-slate-600 dark:bg-slate-800/80"
                                                                    value={
                                                                        row.return_liters
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateRow(
                                                                            pump.id,
                                                                            {
                                                                                return_liters:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                <InputError
                                                                    message={
                                                                        form
                                                                            .errors[
                                                                            `readings.${rowIndex}.return_liters`
                                                                        ]
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 align-top">
                                                            <div className="mx-auto w-40 max-w-full">
                                                                <Input
                                                                    type="text"
                                                                    className="w-full dark:border-slate-600 dark:bg-slate-800/80"
                                                                    value={
                                                                        row.notes
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateRow(
                                                                            pump.id,
                                                                            {
                                                                                notes: e
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            },
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <Button type="submit" disabled={form.processing}>
                                {t('pump_counters.save_all')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {(fuelTypeTotals.length > 0 ||
                    governmentalTotals.length > 0) && (
                    <div className="space-y-3">
                        <h3 className="font-semibold">
                            {isSingleDay
                                ? t('pump_counters.sales_summary_daily')
                                : t('pump_counters.sales_summary_period')}
                        </h3>
                        <div className="flex flex-wrap gap-3">
                            {fuelTypeTotals.map((total) => (
                                <div
                                    key={total.fuel_type_id}
                                    className={TOTAL_BOX}
                                >
                                    <span className={TOTAL_LABEL}>
                                        <bdi>{total.fuel_type_name}</bdi>:
                                    </span>
                                    <span
                                        className={cn(
                                            TOTAL_VALUE,
                                            fuelTypeText[total.fuel_type_id] ??
                                                'text-foreground',
                                        )}
                                    >
                                        {formatNumber(total.liters_sold)} L
                                    </span>
                                </div>
                            ))}
                            {governmentalTotals.length > 0 && (
                                <div className={TOTAL_BOX}>
                                    <span className={TOTAL_LABEL}>
                                        {t('pump_counters.governmental_total')}:
                                    </span>
                                    <span
                                        className={cn(
                                            TOTAL_VALUE,
                                            'text-green-600 dark:text-green-400',
                                        )}
                                    >
                                        {formatNumber(governmentalLiters)} L
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,300px))] gap-4">
                    {pumps.map((pump) => (
                        <Card
                            key={pump.id}
                            className={cn(
                                'border-t-4',
                                fuelTypeCardBorder[pump.fuel_type_ids[0]] ??
                                    'border-t-border',
                            )}
                        >
                            <CardHeader>
                                <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                                    {pump.name}
                                    {pump.fuel_type_names.map((name) => (
                                        <Badge key={name} variant="secondary">
                                            {name}
                                        </Badge>
                                    ))}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div>
                                    <p className="text-muted-foreground text-xs">
                                        {isSingleDay
                                            ? t('pump_counters.daily_total')
                                            : t('pump_counters.period_total')}
                                    </p>
                                    <p
                                        className={cn(
                                            'text-lg font-bold',
                                            pump.daily_liters_sold > 0 &&
                                                'text-success',
                                        )}
                                    >
                                        {formatNumber(pump.daily_liters_sold)} L
                                    </p>
                                </div>
                                {pump.latest_reading ? (
                                    <div>
                                        <p className="text-muted-foreground text-xs">
                                            {t('pump_counters.reading_value')}
                                        </p>
                                        <p className="text-lg font-bold">
                                            {formatNumber(
                                                pump.latest_reading
                                                    .reading_value,
                                                0,
                                            )}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground">
                                        {t('pump_counters.no_previous')}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="space-y-3">
                    <SectionToolbar
                        title={t('pump_counters.history')}
                        actions={
                            <>
                                <GeneratePdfButton
                                    href={exportPdf.url({ query: filters })}
                                />
                                {exportFuelTypes.map((fuelType) => (
                                    <GenerateXlsxButton
                                        key={fuelType.id}
                                        label={fuelType.name}
                                        href={exportXlsx.url({
                                            query: {
                                                fuel_type_id: fuelType.id,
                                                ...filters,
                                            },
                                        })}
                                    />
                                ))}
                            </>
                        }
                    >
                        <DateRangePicker
                            from={filters.from}
                            to={filters.to}
                            onChange={handleRangeChange}
                        />
                    </SectionToolbar>

                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-start">
                                    <th className="px-4 py-3">
                                        {t('pump_counters.time')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.pump')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.tank')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.previous')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.reading_value')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.liters_sold')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.governmental_sale')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('pump_counters.return_liters')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.recorded_by')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.notes')}
                                    </th>
                                    {auth.isAdmin && (
                                        <th className="px-4 py-3"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {readings.map((reading) => (
                                    <tr key={reading.id} className="border-t">
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {formatDateTime(loggedAt(reading))}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.pump?.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.tank
                                                ? `${reading.tank.fuel_type?.name} — ${reading.tank.name}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.previous_reading_value !==
                                            null
                                                ? formatNumber(
                                                      reading.previous_reading_value,
                                                      0,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {formatNumber(
                                                reading.reading_value,
                                                0,
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.liters_sold !== null
                                                ? `${formatNumber(reading.liters_sold)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.governmental_liters !==
                                            null
                                                ? `${formatNumber(reading.governmental_liters)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.return_liters !== null
                                                ? `${formatNumber(reading.return_liters)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.recorded_by?.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.notes}
                                        </td>
                                        {auth.isAdmin && (
                                            <td className="space-x-2 px-4 py-3 text-end">
                                                <Link
                                                    href={edit(reading.id)}
                                                    className="text-sm underline"
                                                >
                                                    {t('common.edit')}
                                                </Link>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeReading(reading)
                                                    }
                                                >
                                                    {t('common.delete')}
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                                {readings.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={auth.isAdmin ? 11 : 10}
                                            className="text-muted-foreground px-4 py-6 text-center"
                                        >
                                            {t('common.no_results')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

PumpCountersIndex.layout = {
    breadcrumbs: [{ title: 'Pump counters', href: index() }],
};
