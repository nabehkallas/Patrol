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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

// Sales total badges in the entry header, and the tinted pump-name badges in the table.
const TOTAL_BADGE =
    'inline-flex items-baseline gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium ring-1 ring-inset';
const NEUTRAL_BADGE =
    'bg-muted text-foreground ring-border dark:bg-slate-800 dark:ring-slate-700';

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
    // matching the same convention used on the Inventory page's tank cards. Used for the entry
    // table's row edge and pump-name badge, and the sales badges in the header.
    const fuelTypeOrder: number[] = [];
    pumps.forEach((pump) => {
        const fuelTypeId = pump.fuel_type_ids[0];

        if (fuelTypeId !== undefined && !fuelTypeOrder.includes(fuelTypeId)) {
            fuelTypeOrder.push(fuelTypeId);
        }
    });
    tanks.forEach((tank) => {
        if (!fuelTypeOrder.includes(tank.fuel_type_id)) {
            fuelTypeOrder.push(tank.fuel_type_id);
        }
    });

    const BADGE_ACCENTS = [
        'bg-amber-50 text-amber-900 ring-amber-300 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/40',
        'bg-blue-50 text-blue-900 ring-blue-300 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-500/40',
    ];
    const ROW_ACCENT_BORDERS = ['border-s-amber-500', 'border-s-blue-500'];
    const fuelTypeBadge: Record<number, string> = {};
    const fuelTypeRowBorder: Record<number, string> = {};
    fuelTypeOrder.forEach((fuelTypeId, i) => {
        fuelTypeBadge[fuelTypeId] = BADGE_ACCENTS[i] ?? NEUTRAL_BADGE;
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

            <div className="mx-auto w-full max-w-6xl space-y-6">
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
                                    className="h-9 w-auto border-slate-300 dark:border-slate-700"
                                    value={form.data.date}
                                    onChange={(e) =>
                                        form.setData('date', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.date} />
                                {(fuelTypeTotals.length > 0 ||
                                    governmentalTotals.length > 0) && (
                                    <div
                                        className="flex flex-wrap items-center gap-2"
                                        title={
                                            isSingleDay
                                                ? t(
                                                      'pump_counters.sales_summary_daily',
                                                  )
                                                : t(
                                                      'pump_counters.sales_summary_period',
                                                  )
                                        }
                                        data-test="sales-badges"
                                    >
                                        {fuelTypeTotals.map((total) => (
                                            <span
                                                key={total.fuel_type_id}
                                                className={cn(
                                                    TOTAL_BADGE,
                                                    fuelTypeBadge[
                                                        total.fuel_type_id
                                                    ] ?? NEUTRAL_BADGE,
                                                )}
                                            >
                                                <bdi>
                                                    {total.fuel_type_name}
                                                </bdi>
                                                :
                                                <bdi
                                                    dir="ltr"
                                                    className="font-bold tabular-nums"
                                                >
                                                    {formatNumber(
                                                        total.liters_sold,
                                                    )}{' '}
                                                    L
                                                </bdi>
                                            </span>
                                        ))}
                                        {governmentalTotals.length > 0 && (
                                            <span
                                                className={cn(
                                                    TOTAL_BADGE,
                                                    'bg-green-50 text-green-900 ring-green-300 dark:bg-green-500/15 dark:text-green-200 dark:ring-green-500/40',
                                                )}
                                            >
                                                {t(
                                                    'pump_counters.governmental_total',
                                                )}
                                                :
                                                <bdi
                                                    dir="ltr"
                                                    className="font-bold tabular-nums"
                                                >
                                                    {formatNumber(
                                                        governmentalLiters,
                                                    )}{' '}
                                                    L
                                                </bdi>
                                            </span>
                                        )}
                                    </div>
                                )}
                                <div className="ms-auto flex flex-wrap items-center gap-3">
                                    <SystemLastEntry entry={lastEntry} />
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        data-test="save-all-readings"
                                    >
                                        {t('pump_counters.save_all')}
                                    </Button>
                                </div>
                            </div>

                            <InputError message={form.errors.readings} />

                            <div className="table-stack overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-3 py-2.5 text-center align-top">
                                                {t('pump_counters.pump')}
                                            </th>
                                            <th className="px-3 py-2.5 text-center align-top">
                                                {t('common.tank')}
                                            </th>
                                            <th className="px-3 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.reading_value',
                                                )}
                                            </th>
                                            <th className="px-3 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.governmental_sale',
                                                )}
                                            </th>
                                            <th className="px-3 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.return_liters',
                                                )}
                                            </th>
                                            <th className="px-3 py-2.5 text-center align-top">
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
                                                const rowFuelTypeId =
                                                    pump.fuel_type_ids[0] ??
                                                    tanks.find(
                                                        (tank) =>
                                                            String(tank.id) ===
                                                            row.tank_id,
                                                    )?.fuel_type_id;
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
                                                            'hover:bg-muted/40 border-s-4 border-t transition-colors',
                                                            fuelTypeRowBorder[
                                                                rowFuelTypeId ??
                                                                    -1
                                                            ] ??
                                                                'border-s-border',
                                                        )}
                                                    >
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="flex min-h-9 items-center justify-center">
                                                                <span
                                                                    className={cn(
                                                                        'whitespace-nowrap rounded-md px-2.5 py-1 text-base font-semibold ring-1 ring-inset',
                                                                        fuelTypeBadge[
                                                                            rowFuelTypeId ??
                                                                                -1
                                                                        ] ??
                                                                            NEUTRAL_BADGE,
                                                                    )}
                                                                    title={pump.fuel_type_names.join(
                                                                        ', ',
                                                                    )}
                                                                >
                                                                    {pump.name}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td className="px-3 py-2.5 align-top">
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
                                                                <SelectTrigger className="mx-auto w-44 max-w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80">
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
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="mx-auto w-40 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="1"
                                                                    min="0"
                                                                    className="w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80"
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
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="mx-auto w-28 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    className="w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80"
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
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="mx-auto w-28 max-w-full">
                                                                <Input
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    className="w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80"
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
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="mx-auto w-36 max-w-full">
                                                                <Input
                                                                    type="text"
                                                                    className="w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80"
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
                        </form>
                    </CardContent>
                </Card>

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

                    <div className="table-stack overflow-x-auto rounded-xl border">
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
    breadcrumbs: [{ title: 'nav.pump_counters', href: index() }],
};
