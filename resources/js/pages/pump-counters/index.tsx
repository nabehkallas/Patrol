import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    formatDate,
    formatDateTime,
    formatNumber,
    nowTimeInStation,
    todayInStation,
} from '@/lib/format';
import { useFuelColors } from '@/lib/fuel-colors';
import { useTranslation } from '@/lib/i18n';
import { setLeaveGuard } from '@/lib/leave-guard';
import type { ShiftOption } from '@/lib/shifts';
import { currentShift } from '@/lib/shifts';
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

/** Sales saved in the entry date + shift, for one fuel type. */
type FuelTypeTotal = {
    fuel_type_id: number;
    fuel_type_name: string;
    liters_sold: number;
    governmental_liters: number;
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
    entry: {
        date: string;
        shift_id: number;
        requested: boolean;
        pumps: EntryPump[];
        totals: FuelTypeTotal[];
    };
    shifts: ShiftOption[];
    tanks: TankOption[];
    readings: PumpCounterReading[];
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
/** A date + shift in the entry table. */
type Place = { date: string; shift_id: number };

/** Whether the entry context is for a day before today (station time). */
function isPast(entry: Entry): boolean {
    return entry.date < todayInStation();
}

// One empty row per pump. Saved readings are never put back into the inputs: a pump that has
// one in the date + shift shows a check (and an edit link) instead.
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

// The row's tank stays in the list even if it's since been deactivated.
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
    const { auth, pumps, lastEntry, entry, shifts, tanks, readings, filters } =
        usePage<PageProps>().props;
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

    // A station with a single active shift never sees the selector.
    const activeShifts = shifts.filter((shift) => shift.is_active);
    const showShifts = activeShifts.length > 1;

    // The default date + shift: after saving, the one just saved; otherwise the shift whose
    // times cover now. A night shift's hours after midnight belong to the day it started.
    const [initialDate, initialShift] = useMemo(() => {
        if (entry.requested) {
            return [entry.date, entry.shift_id];
        }

        const now = currentShift(activeShifts, nowTimeInStation());
        const date =
            now?.previousDay && defaultEntryDate === todayInStation()
                ? todayInStation(-1)
                : defaultEntryDate;

        return [date, now?.shift.id ?? entry.shift_id];
        // Only for the first render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const form = useForm({
        date: initialDate,
        shift_id: initialShift,
        readings: buildRows(sortedPumps, tanks),
    });

    function updateRow(pumpId: number, patch: Partial<BulkRow>) {
        // A server message about a field goes once that field is edited again.
        const rowIndex = form.data.readings.findIndex(
            (row) => row.pump_id === pumpId,
        );
        const fields = Object.keys(patch).flatMap((field) =>
            field === 'governmental_liters'
                ? [field, 'return_liters']
                : [field],
        );
        form.clearErrors(
            ...(fields.map(
                (field) => `readings.${rowIndex}.${field}`,
            ) as never[]),
        );

        form.setData(
            'readings',
            form.data.readings.map((row) =>
                row.pump_id === pumpId ? { ...row, ...patch } : row,
            ),
        );
    }

    // Typed values belong to the date + shift they were typed for, so they never carry over to
    // another one: switching with unsaved values asks first -- save them there and switch,
    // discard them and switch, or stay. (A tank choice alone isn't an unsaved value.)
    const hasUnsaved = form.data.readings.some(
        (row) =>
            row.reading_value !== '' ||
            row.governmental_liters !== '' ||
            row.return_liters !== '' ||
            row.notes !== '',
    );
    const [pendingSwitch, setPendingSwitch] = useState<Place | null>(null);
    // "Save, then switch" under way: the switch happens once the save has succeeded.
    const switchAfterSave = useRef<Place | null>(null);
    // A date being typed in the field, until it's a whole date.
    const [dateDraft, setDateDraft] = useState<string | null>(null);

    // Where the typed values belong, for the question: "16/10/2026 - مسائية" (the date alone
    // on a single-shift station).
    const entryPlace = [
        formatDate(form.data.date),
        showShifts
            ? shifts.find((shift) => shift.id === form.data.shift_id)?.name
            : null,
    ]
        .filter(Boolean)
        .join(' - ');

    function switchTo(place: Place) {
        form.clearErrors();
        form.setData((data) => ({
            ...data,
            ...place,
            readings: buildRows(sortedPumps, tanks),
        }));
    }

    function requestSwitch(place: Place) {
        if (
            place.date === form.data.date &&
            place.shift_id === form.data.shift_id
        ) {
            return;
        }

        if (hasUnsaved) {
            setPendingSwitch(place);

            return;
        }

        form.setData((data) => ({ ...data, ...place }));
    }

    function changeDate(value: string) {
        // Typing a year goes through 0002, 0020, ...: only a whole date switches.
        if (/^\d{4}-\d{2}-\d{2}$/.test(value) && value >= '2000-01-01') {
            setDateDraft(null);
            requestSwitch({ date: value, shift_id: form.data.shift_id });

            return;
        }

        setDateDraft(value);
    }

    function saveThenSwitch() {
        switchAfterSave.current = pendingSwitch;
        setPendingSwitch(null);
        askAdditional();
    }

    function discardThenSwitch() {
        if (pendingSwitch) {
            switchTo(pendingSwitch);
        }

        setPendingSwitch(null);
    }

    // Leaving the page with unsaved values asks first: closing or reloading the tab, a link
    // (the sidebar), or the browser's back/forward. Reloading this page's own data doesn't.
    useEffect(() => {
        if (!hasUnsaved) {
            return;
        }

        const message = t('pump_counters.leave_confirm');
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        const off = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (
                visit.method === 'get' &&
                visit.preserveState !== true &&
                !window.confirm(message)
            ) {
                event.preventDefault();
            }
        });
        const offBack = setLeaveGuard(() => window.confirm(message));

        window.addEventListener('beforeunload', warn);

        return () => {
            window.removeEventListener('beforeunload', warn);
            off();
            offBack();
        };
    }, [hasUnsaved, t]);

    // What's saved, the bounds and the chips are for the date + shift being entered: reload
    // them when either changes. A new date also sets the history below to that one day (a range
    // picked there afterwards stays until the date changes again).
    const entryDate = form.data.date;
    const entryShift = form.data.shift_id;

    useEffect(() => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(entryDate)) {
            return;
        }

        const newDate = entryDate !== entry.date;

        if (newDate || entryShift !== entry.shift_id) {
            router.reload({
                data: {
                    entry_date: entryDate,
                    entry_shift: entryShift,
                    ...(newDate ? { from: entryDate, to: entryDate } : {}),
                },
                only: newDate ? ['entry', 'readings', 'filters'] : ['entry'],
            });
        }
    }, [entryDate, entryShift, entry.date, entry.shift_id]);

    // A past day: pumps with nothing saved in that date + shift are marked.
    const isPastDay =
        entry.date === entryDate &&
        entry.shift_id === entryShift &&
        isPast(entry);

    const filledRows = (skip: number[]) =>
        form.data.readings.filter(
            (row) => row.reading_value !== '' && !skip.includes(row.pump_id),
        );
    const pumpName = (pumpId: number) => pumpsById.get(pumpId)?.name ?? '';

    // Two questions before saving, in turn: a value for a pump already recorded in this date +
    // shift is an additional reading (a tank switch) -- cancelling leaves that pump out; and a
    // newest reading lower than the previous one means the meter was replaced -- cancelling
    // saves nothing.
    const [confirm, setConfirm] = useState<{
        kind: 'additional' | 'lower';
        pumpIds: number[];
        skip: number[];
    } | null>(null);

    function askAdditional() {
        const pumpIds = filledRows([])
            .filter(
                (row) =>
                    (entryByPump.get(row.pump_id)?.recorded.length ?? 0) > 0,
            )
            .map((row) => row.pump_id);

        if (pumpIds.length > 0) {
            setConfirm({ kind: 'additional', pumpIds, skip: [] });

            return;
        }

        askLower([]);
    }

    function askLower(skip: number[]) {
        const pumpIds = filledRows(skip)
            .filter((row) => {
                const context = entryByPump.get(row.pump_id);

                return (
                    !!context?.previous &&
                    !context.next &&
                    Number(row.reading_value) < context.previous.reading_value
                );
            })
            .map((row) => row.pump_id);

        if (pumpIds.length > 0) {
            setConfirm({ kind: 'lower', pumpIds, skip });

            return;
        }

        save(skip);
    }

    // Closing a question (or cancelling "meter replaced") saves nothing, and stays put.
    function cancelConfirm() {
        switchAfterSave.current = null;
        setConfirm(null);
    }

    function save(skip: number[]) {
        setConfirm(null);

        if (filledRows(skip).length === 0) {
            // Nothing left to save (every pump was left out): go on with the switch.
            if (switchAfterSave.current) {
                switchTo(switchAfterSave.current);
                switchAfterSave.current = null;
            }

            return;
        }

        // Skipped pumps are sent blank (rows keep their place, so errors still point at them).
        form.transform((data) => ({
            ...data,
            readings: data.readings.map((row) =>
                skip.includes(row.pump_id)
                    ? { ...row, reading_value: '' }
                    : row,
            ),
        }));
        // Saved to the date + shift in the form, i.e. where they were typed; a switch waiting
        // on this save happens only once it succeeded (with errors, everything stays).
        form.post(storeBulk.url(), {
            preserveScroll: true,
            onSuccess: () => {
                const next = switchAfterSave.current;
                switchAfterSave.current = null;

                if (next) {
                    switchTo(next);
                } else {
                    form.setData('readings', buildRows(sortedPumps, tanks));
                }
            },
            onError: () => {
                switchAfterSave.current = null;
            },
        });
    }

    function submitAll(event: FormEvent) {
        event.preventDefault();
        askAdditional();
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
            {
                ...range,
                entry_date: form.data.date,
                entry_shift: form.data.shift_id,
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }

    // The chips: what's saved in the entry date + shift, plus what's typed and not saved yet
    // (liters a typed value sells from its previous reading, and its governmental liters, by
    // the row's tank). Chips with nothing in them are left out.
    const chipTotals = useMemo(() => {
        const totals = new Map<number, FuelTypeTotal>(
            entry.totals.map((total) => [total.fuel_type_id, { ...total }]),
        );

        for (const row of form.data.readings) {
            const previous = entryByPump.get(row.pump_id)?.previous;
            const tank = tanks.find(
                (option) => String(option.id) === row.tank_id,
            );

            if (row.reading_value === '' || !tank) {
                continue;
            }

            const total = totals.get(tank.fuel_type_id) ?? {
                fuel_type_id: tank.fuel_type_id,
                fuel_type_name: tank.fuel_type_name,
                liters_sold: 0,
                governmental_liters: 0,
            };
            const sold = previous
                ? Number(row.reading_value) - previous.reading_value
                : 0;
            total.liters_sold += Math.max(sold, 0);
            total.governmental_liters += Number(row.governmental_liters || 0);
            totals.set(tank.fuel_type_id, total);
        }

        return [...totals.values()].sort((a, b) =>
            a.fuel_type_name.localeCompare(b.fuel_type_name),
        );
    }, [entry.totals, form.data.readings, entryByPump, tanks]);
    const litersChips = chipTotals.filter((total) => total.liters_sold > 0);
    const governmentalChips = chipTotals.filter(
        (total) => total.governmental_liters > 0,
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
                                    value={
                                        pendingSwitch?.date ??
                                        dateDraft ??
                                        form.data.date
                                    }
                                    onChange={(e) => changeDate(e.target.value)}
                                    onBlur={() => setDateDraft(null)}
                                />
                                {showShifts && (
                                    <Select
                                        value={String(
                                            pendingSwitch?.shift_id ??
                                                form.data.shift_id,
                                        )}
                                        onValueChange={(value) =>
                                            requestSwitch({
                                                date: form.data.date,
                                                shift_id: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="h-9 w-auto min-w-[8rem] border-slate-300 dark:border-slate-700"
                                            aria-label={t(
                                                'pump_counters.shift',
                                            )}
                                            data-test="shift-select"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {activeShifts.map((shift) => (
                                                <SelectItem
                                                    key={shift.id}
                                                    value={String(shift.id)}
                                                >
                                                    {shift.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {isPastDay && (
                                    <span
                                        className="inline-flex items-center whitespace-nowrap rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                                        data-test="past-date-badge"
                                    >
                                        {t('pump_counters.past_date')}
                                    </span>
                                )}
                                <InputError message={form.errors.date} />
                                {(litersChips.length > 0 ||
                                    governmentalChips.length > 0) && (
                                    <div
                                        className="flex flex-wrap items-center gap-2"
                                        title={t(
                                            'pump_counters.sales_summary_daily',
                                        )}
                                        data-test="sales-badges"
                                    >
                                        {litersChips.map((total) => (
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
                                        {governmentalChips.map((total) => (
                                            <span
                                                key={`gov-${total.fuel_type_id}`}
                                                className={cn(
                                                    TOTAL_BADGE,
                                                    fuelColors.byId(
                                                        total.fuel_type_id,
                                                    ).badge,
                                                )}
                                                data-test="governmental-chip"
                                            >
                                                <bdi>
                                                    {t(
                                                        'pump_counters.governmental_fuel',
                                                        {
                                                            fuel: total.fuel_type_name,
                                                        },
                                                    )}
                                                </bdi>
                                                :
                                                <bdi
                                                    dir="ltr"
                                                    className="font-bold tabular-nums"
                                                >
                                                    {formatNumber(
                                                        total.governmental_liters,
                                                    )}{' '}
                                                    L
                                                </bdi>
                                            </span>
                                        ))}
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
                                            <th className="w-px whitespace-nowrap px-1.5 py-2.5 text-center align-top">
                                                {t('pump_counters.pump')}
                                            </th>
                                            <th className="px-1.5 py-2.5 text-center align-top">
                                                {t('common.tank')}
                                            </th>
                                            <th className="px-1.5 py-2.5 text-center align-top">
                                                {t('pump_counters.previous')}
                                            </th>
                                            <th className="px-1.5 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.reading_value',
                                                )}
                                            </th>
                                            <th className="px-1.5 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.sold_quantity',
                                                )}
                                            </th>
                                            <th className="w-20 px-1.5 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.governmental_sale',
                                                )}
                                            </th>
                                            <th className="w-20 px-1.5 py-2.5 text-center align-top">
                                                {t(
                                                    'pump_counters.return_liters',
                                                )}
                                            </th>
                                            {/* Notes take whatever width is left; the number columns stay compact. */}
                                            <th className="w-full px-1.5 py-2.5 text-center align-top">
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
                        {/* Closing the dialog (X, Escape, outside click) saves nothing. */}
                        <Dialog
                            open={confirm !== null}
                            onOpenChange={(open) => !open && cancelConfirm()}
                        >
                            <DialogContent
                                className="sm:max-w-md"
                                data-test={
                                    confirm?.kind === 'additional'
                                        ? 'additional-reading-dialog'
                                        : 'meter-replaced-dialog'
                                }
                            >
                                <DialogTitle>
                                    {confirm?.kind === 'additional'
                                        ? t('pump_counters.additional_title')
                                        : t(
                                              'pump_counters.meter_replaced_title',
                                          )}
                                </DialogTitle>
                                <DialogDescription>
                                    {confirm?.kind === 'additional'
                                        ? t('pump_counters.additional_confirm')
                                        : t(
                                              'pump_counters.meter_replaced_confirm',
                                          )}
                                    {confirm && (
                                        <bdi className="mt-2 block font-medium">
                                            {confirm.pumpIds
                                                .map(pumpName)
                                                .join('، ')}
                                        </bdi>
                                    )}
                                </DialogDescription>
                                <DialogFooter className="gap-2">
                                    {/* Cancel on "another reading" leaves those pumps out and
                                        goes on with the rest; on "meter replaced" it stops. */}
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            confirm?.kind === 'additional'
                                                ? askLower(confirm.pumpIds)
                                                : cancelConfirm()
                                        }
                                    >
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        onClick={() =>
                                            confirm?.kind === 'additional'
                                                ? askLower(confirm.skip)
                                                : save(confirm?.skip ?? [])
                                        }
                                    >
                                        {confirm?.kind === 'additional'
                                            ? t('pump_counters.additional_yes')
                                            : t(
                                                  'pump_counters.meter_replaced_yes',
                                              )}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        {/* Switching date or shift with unsaved values. Closing it = cancel. */}
                        <Dialog
                            open={pendingSwitch !== null}
                            onOpenChange={(open) =>
                                !open && setPendingSwitch(null)
                            }
                        >
                            <DialogContent
                                className="sm:max-w-md"
                                data-test="unsaved-switch-dialog"
                            >
                                <DialogTitle>
                                    {t('pump_counters.unsaved_title')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('pump_counters.unsaved_message', {
                                        place: entryPlace,
                                    })}
                                </DialogDescription>
                                <DialogFooter className="gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => setPendingSwitch(null)}
                                    >
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={discardThenSwitch}
                                    >
                                        {t('pump_counters.discard')}
                                    </Button>
                                    <Button onClick={saveThenSwitch}>
                                        {t('pump_counters.save_and_switch')}
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
