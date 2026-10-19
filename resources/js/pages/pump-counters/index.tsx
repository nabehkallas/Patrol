import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import type {
    EntryPump,
    EntryRowData,
    EntryTank,
} from '@/components/pump-counters/entry-row';
import { EntryRow } from '@/components/pump-counters/entry-row';
import type { EntryStamp } from '@/components/pump-counters/last-entry';
import { SystemLastEntry } from '@/components/pump-counters/last-entry';
import { RowActions } from '@/components/row-actions';
import { SectionToolbar } from '@/components/section-toolbar';
import { FuelTypeDot } from '@/components/tank-select-items';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import { formatDateTime, formatNumber, todayInStation } from '@/lib/format';
import { useFuelColors } from '@/lib/fuel-colors';
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

type TankOption = EntryTank;

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
    entry: { date: string; requested: boolean; pumps: EntryPump[] };
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
    'inline-flex max-w-full items-baseline gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm font-medium ring-1 ring-inset';
const NEUTRAL_BADGE =
    'bg-muted text-foreground ring-border dark:bg-slate-800 dark:ring-slate-700';

type BulkRow = EntryRowData;
type Entry = PageProps['entry'];

/** Whether the entry context is for a day before today (station time). */
function isPast(entry: Entry): boolean {
    return entry.date < todayInStation();
}

// One row per pump. On a past day a pump's saved reading comes back prefilled (with its id, so
// saving updates it); every other row starts empty.
function buildRows(
    pumpList: PumpSummary[],
    tanks: TankOption[],
    entry: Entry,
): BulkRow[] {
    const byPump = new Map(entry.pumps.map((row) => [row.pump_id, row]));
    const past = isPast(entry);

    return pumpList.map((pump) => {
        const saved = past ? (byPump.get(pump.id)?.recorded ?? null) : null;
        const defaultTank = String(defaultTankFor(pump, tanksFor(pump, tanks)));

        return saved
            ? {
                  id: saved.id,
                  pump_id: pump.id,
                  tank_id:
                      saved.tank_id !== null
                          ? String(saved.tank_id)
                          : defaultTank,
                  reading_value: String(saved.reading_value),
                  governmental_liters:
                      saved.governmental_liters !== null
                          ? String(saved.governmental_liters)
                          : '',
                  return_liters:
                      saved.return_liters !== null
                          ? String(saved.return_liters)
                          : '',
                  notes: saved.notes ?? '',
              }
            : {
                  id: null,
                  pump_id: pump.id,
                  tank_id: defaultTank,
                  reading_value: '',
                  governmental_liters: '',
                  return_liters: '',
                  notes: '',
              };
    });
}

// A saved reading keeps its own tank in the list, even one that's since been deactivated.
function rowTanks(
    pump: PumpSummary,
    row: BulkRow,
    tanks: TankOption[],
): TankOption[] {
    const options = tanksFor(pump, tanks);
    const own = tanks.find((tank) => String(tank.id) === row.tank_id);

    return own && !options.includes(own) ? [own, ...options] : options;
}

export default function PumpCountersIndex() {
    const {
        auth,
        pumps,
        lastEntry,
        entry,
        tanks,
        readings,
        fuelTypeTotals,
        governmentalTotals,
        filters,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const defaultEntryDate = useDefaultEntryDate();

    // Fuel types in order of first appearance among the pumps (then tanks), to keep each fuel
    // type's pumps together in the entry table.
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

    // Each fuel type's own colour: the entry table's row edge and pump-name badge, and the
    // sales badges in the header.
    const fuelColors = useFuelColors();
    const fuelTypeBadge: Record<number, string> = {};
    const fuelTypeRowBorder: Record<number, string> = {};
    fuelTypeOrder.forEach((fuelTypeId) => {
        fuelTypeBadge[fuelTypeId] = fuelColors.byId(fuelTypeId).badge;
        fuelTypeRowBorder[fuelTypeId] = fuelColors.byId(fuelTypeId).borderStart;
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

    const entryByPump = useMemo(
        () => new Map(entry.pumps.map((row) => [row.pump_id, row])),
        [entry.pumps],
    );

    const form = useForm({
        // After saving a past day the page comes back on that day, not on today.
        date: entry.requested ? entry.date : defaultEntryDate,
        readings: buildRows(sortedPumps, tanks, entry),
    });

    function updateRow(pumpId: number, patch: Partial<BulkRow>) {
        form.setData(
            'readings',
            form.data.readings.map((row) =>
                row.pump_id === pumpId ? { ...row, ...patch } : row,
            ),
        );
    }

    // The hints and bounds below are for the day being entered: reload them when it changes.
    const entryDate = form.data.date;

    useEffect(() => {
        if (/^\d{4}-\d{2}-\d{2}$/.test(entryDate) && entryDate !== entry.date) {
            router.reload({ data: { entry_date: entryDate }, only: ['entry'] });
        }
    }, [entryDate, entry.date]);

    // A past day: saved readings are prefilled and editable, missed pumps are marked.
    const isPastDay = entry.date === entryDate && isPast(entry);

    // Rows follow the day being entered: rebuilt (prefilled) whenever that day's context loads.
    useEffect(() => {
        form.setData('readings', buildRows(sortedPumps, tanks, entry));
        // Only on a new day; typing must never be reset by a re-render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [entry.date]);

    // The newest reading may be lower than the one before it only when the meter was replaced:
    // ask before saving it.
    const [lowerPumps, setLowerPumps] = useState<string[] | null>(null);

    function lowerThanPrevious(): string[] {
        return form.data.readings
            .filter((row) => {
                const context = entryByPump.get(row.pump_id);

                if (
                    row.reading_value === '' ||
                    !context?.previous ||
                    context.next
                ) {
                    return false;
                }

                const value = Number(row.reading_value);
                const unchanged =
                    row.id !== null &&
                    context.recorded?.reading_value === value;

                return !unchanged && value < context.previous.reading_value;
            })
            .map((row) => pumpsById.get(row.pump_id)?.name ?? '');
    }

    function save() {
        setLowerPumps(null);
        form.post(storeBulk.url(), {
            preserveScroll: true,
            onSuccess: (page) => {
                form.setData(
                    'readings',
                    buildRows(
                        sortedPumps,
                        tanks,
                        (page.props as unknown as PageProps).entry,
                    ),
                );
            },
        });
    }

    function submitAll(event: FormEvent) {
        event.preventDefault();
        const lower = lowerThanPrevious();

        if (lower.length > 0) {
            setLowerPumps(lower);

            return;
        }

        save();
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
        router.get(
            index(),
            { ...range, entry_date: form.data.date },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }

    const isSingleDay = filters.from === filters.to;
    const governmentalLiters = governmentalTotals.reduce(
        (sum, total) => sum + total.liters_sold,
        0,
    );

    function removeReading(reading: PumpCounterReading) {
        router.delete(destroy.url(reading.id));
    }

    return (
        <>
            <Head title={t('pump_counters.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('pump_counters.title')}
                    description={t('pump_counters.description')}
                />

                <Card>
                    <CardContent>
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
                                {isPastDay && (
                                    <span
                                        className="inline-flex items-center whitespace-nowrap rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                                        data-test="past-date-badge"
                                    >
                                        {t('pump_counters.past_date')}
                                    </span>
                                )}
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
                                                    fuelColors.byId(
                                                        total.fuel_type_id,
                                                    ).badge,
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
                                            <th className="w-px whitespace-nowrap px-3 py-2.5 text-center align-top">
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

                                                const rowFuelTypeId =
                                                    pump.fuel_type_ids[0] ??
                                                    tanks.find(
                                                        (tank) =>
                                                            String(tank.id) ===
                                                            row.tank_id,
                                                    )?.fuel_type_id;

                                                return (
                                                    <EntryRow
                                                        key={pump.id}
                                                        row={row}
                                                        rowIndex={rowIndex}
                                                        pumpName={pump.name}
                                                        pumpTitle={[
                                                            pump.name,
                                                            ...pump.fuel_type_names,
                                                        ].join(' — ')}
                                                        badgeClass={
                                                            fuelTypeBadge[
                                                                rowFuelTypeId ??
                                                                    -1
                                                            ] ?? NEUTRAL_BADGE
                                                        }
                                                        rowBorderClass={
                                                            fuelTypeRowBorder[
                                                                rowFuelTypeId ??
                                                                    -1
                                                            ] ??
                                                            'border-s-border'
                                                        }
                                                        tankOptions={rowTanks(
                                                            pump,
                                                            row,
                                                            tanks,
                                                        )}
                                                        context={entryByPump.get(
                                                            pump.id,
                                                        )}
                                                        isPastDay={isPastDay}
                                                        canEditSaved={
                                                            auth.isAdmin
                                                        }
                                                        errors={form.errors}
                                                        onChange={(patch) =>
                                                            updateRow(
                                                                pump.id,
                                                                patch,
                                                            )
                                                        }
                                                    />
                                                );
                                            },
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </form>
                        <Dialog
                            open={lowerPumps !== null}
                            onOpenChange={(open) =>
                                !open && setLowerPumps(null)
                            }
                        >
                            <DialogContent
                                className="sm:max-w-md"
                                data-test="meter-replaced-dialog"
                            >
                                <DialogTitle>
                                    {t('pump_counters.meter_replaced_title')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('pump_counters.meter_replaced_confirm')}
                                    {lowerPumps && lowerPumps.length > 0 && (
                                        <bdi className="mt-2 block font-medium">
                                            {lowerPumps.join('، ')}
                                        </bdi>
                                    )}
                                </DialogDescription>
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="outline">
                                            {t('common.cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button onClick={save}>
                                        {t('pump_counters.meter_replaced_yes')}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
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
                                        label={t('common.xlsx_for', {
                                            name: fuelType.name,
                                        })}
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
                                        {t('common.notes')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.recorded_by')}
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
                                            {reading.tank ? (
                                                <span
                                                    className="flex min-w-0 items-center whitespace-nowrap"
                                                    title={reading.tank.name}
                                                >
                                                    <FuelTypeDot
                                                        className={
                                                            fuelColors.byId(
                                                                reading.tank
                                                                    .fuel_type_id,
                                                            ).dot
                                                        }
                                                    />
                                                    <span className="truncate [unicode-bidi:plaintext]">
                                                        {reading.tank.name}
                                                    </span>
                                                </span>
                                            ) : (
                                                '—'
                                            )}
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
                                            {reading.notes}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reading.recorded_by?.name}
                                        </td>
                                        {auth.isAdmin && (
                                            <td className="px-4 py-3 text-end">
                                                <RowActions
                                                    edit={edit(reading.id).url}
                                                    remove={() =>
                                                        removeReading(reading)
                                                    }
                                                />
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
