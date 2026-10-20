import { Link } from '@inertiajs/react';
import { CheckCircle2, Pencil } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { TankSelectItems } from '@/components/tank-select-items';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as editReading } from '@/routes/pump-counters';
import type { EntryStamp } from './last-entry';

export type EntryTank = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    is_active: boolean;
};

/**
 * One pump in the date + shift being entered: the readings a new value must sit between (the
 * previous one is what it counts from), and the readings already saved there (never put back
 * into the inputs), each with the value it counted from and its liters sold.
 */
export type EntryPump = {
    pump_id: number;
    previous: (EntryStamp & { reading_value: number }) | null;
    next: { reading_value: number; date: string } | null;
    recorded: {
        id: number;
        reading_value: number;
        previous_value: number | null;
        liters_sold: number | null;
    }[];
};

/** A row of the entry table: always a new reading, empty until typed. */
export type EntryRowData = {
    pump_id: number;
    tank_id: string;
    reading_value: string;
    governmental_liters: string;
    return_liters: string;
    notes: string;
};

const INPUT =
    'w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80';

/**
 * A value in the row that can't be typed in: an input's height, muted, never focused. Its
 * width is fixed (a long value is cut short, in full on hover), so it never widens the table.
 */
function LockedBox({
    label,
    value,
    className,
    ...props
}: ComponentProps<'div'> & { label: string; value: string }) {
    return (
        <div
            dir="ltr"
            aria-readonly
            aria-label={label}
            title={`${label}: ${value}`}
            className={cn(
                'bg-muted/70 text-muted-foreground flex h-9 cursor-default select-none items-center justify-end overflow-hidden whitespace-nowrap rounded-md border border-slate-200 px-2.5 text-sm tabular-nums dark:border-slate-700/60 dark:bg-slate-900/60',
                className,
            )}
            {...props}
        >
            <span className="truncate">{value}</span>
        </div>
    );
}

type Props = {
    row: EntryRowData;
    rowIndex: number;
    pumpName: string;
    pumpTitle: string;
    badgeClass: string;
    rowBorderClass: string;
    tankOptions: EntryTank[];
    context: EntryPump | undefined;
    /** A past day: pumps with nothing saved in that date + shift are marked. */
    isPastDay: boolean;
    /** Saved readings are changed by admins only, on the edit page. */
    canEditSaved: boolean;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<EntryRowData>) => void;
};

export function EntryRow({
    row,
    rowIndex,
    pumpName,
    pumpTitle,
    badgeClass,
    rowBorderClass,
    tankOptions,
    context,
    isPastDay,
    canEditSaved,
    errors,
    onChange,
}: Props) {
    const { t } = useTranslation();
    const previous = context?.previous ?? null;
    const next = context?.next ?? null;
    const recorded = context?.recorded ?? [];
    const saved = recorded.length > 0;
    const missing = isPastDay && !saved;
    const lastSaved = recorded.at(-1);
    const inputClass = cn(
        INPUT,
        missing && 'border-amber-300 dark:border-amber-600/70',
    );
    const error = (field: string) => errors[`readings.${rowIndex}.${field}`];

    // Checked once the field is left (and again by the server on save), never while typing.
    const [checked, setChecked] = useState<Record<string, boolean>>({});
    const check = (field: string) =>
        setChecked((current) => ({ ...current, [field]: true }));
    const edit = (field: keyof EntryRowData, value: string) => {
        setChecked((current) => ({ ...current, [field]: false }));
        onChange({ [field]: value });
    };

    const value = row.reading_value === '' ? null : Number(row.reading_value);
    const outOfRange =
        value !== null &&
        next !== null &&
        (value > next.reading_value ||
            (previous !== null && value < previous.reading_value));
    const litersSold =
        previous && value !== null && value > previous.reading_value
            ? value - previous.reading_value
            : null;
    // The locked boxes: what a typed value counts from and sells. With nothing typed, a pump
    // recorded in this date + shift shows its saved reading's; a typed (additional) value counts
    // from the saved reading, which by then is the previous one.
    const shown =
        value === null && lastSaved
            ? {
                  previous: lastSaved.previous_value,
                  sold: lastSaved.liters_sold,
              }
            : {
                  previous: previous?.reading_value ?? null,
                  sold:
                      value !== null && previous
                          ? value - previous.reading_value
                          : null,
              };
    const soldTone =
        value === null || shown.sold === null
            ? undefined
            : shown.sold < 0
              ? 'border-red-300 font-semibold text-destructive dark:border-red-800/70'
              : shown.sold === 0
                ? 'border-amber-300 font-semibold text-amber-700 dark:border-amber-700/70 dark:text-amber-300'
                : 'text-foreground';
    const deducted =
        Number(row.governmental_liters || 0) + Number(row.return_liters || 0);
    const tooManyDeducted =
        litersSold !== null && deducted > litersSold + 0.0005;

    const readingMessage =
        error('reading_value') ??
        (checked.reading_value && outOfRange && next
            ? previous
                ? t('pump_counters.between', {
                      previous: formatNumber(previous.reading_value, 0),
                      next: formatNumber(next.reading_value, 0),
                  })
                : t('pump_counters.at_most', {
                      next: formatNumber(next.reading_value, 0),
                  })
            : undefined);
    const deductedMessage =
        error('return_liters') ??
        ((checked.governmental_liters || checked.return_liters) &&
        tooManyDeducted
            ? t(
                  'Governmental and return liters cannot exceed the liters sold (:liters L).',
                  { liters: formatNumber(litersSold, 3) },
              )
            : undefined);

    return (
        <tr
            className={cn(
                'border-s-4 border-t transition-colors',
                rowBorderClass,
            )}
            data-test="entry-row"
            data-state={saved ? 'saved' : missing ? 'missing' : 'new'}
        >
            <td className="px-1.5 py-2.5 align-top">
                <div className="flex min-h-9 items-center justify-center">
                    {/* The check sits on the badge's corner, so it adds no width to the column. */}
                    <span className="relative inline-flex max-w-full">
                        <span
                            className={cn(
                                'inline-block max-w-[7rem] truncate whitespace-nowrap rounded-md px-2.5 py-1 text-center text-base font-semibold leading-6 ring-1 ring-inset [unicode-bidi:plaintext]',
                                badgeClass,
                            )}
                            title={pumpTitle}
                        >
                            {pumpName}
                        </span>
                        {saved && (
                            <CheckCircle2
                                className="bg-card absolute -end-1.5 -top-1.5 size-3.5 rounded-full text-emerald-600/80 dark:text-emerald-400/80"
                                aria-label={t('pump_counters.recorded_on_day')}
                                data-test="saved-check"
                            />
                        )}
                    </span>
                    {/* Opens the shift's (last) saved reading on the edit page. */}
                    {saved && canEditSaved && lastSaved && (
                        <Link
                            href={editReading(lastSaved.id).url}
                            className="text-muted-foreground hover:text-foreground ms-2 shrink-0 rounded p-0.5"
                            title={`${t('common.edit')}: ${formatNumber(lastSaved.reading_value, 0)}`}
                            aria-label={t('common.edit')}
                            data-test="edit-saved"
                        >
                            <Pencil className="size-3.5" />
                        </Link>
                    )}
                </div>
            </td>
            <td className="px-1.5 py-2.5 align-top">
                <Select
                    value={row.tank_id}
                    onValueChange={(tankId) => onChange({ tank_id: tankId })}
                >
                    <SelectTrigger className="w-full min-w-[6.5rem] max-w-[12rem] border-slate-300 dark:border-slate-700 dark:bg-slate-800/80">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <TankSelectItems tanks={tankOptions} />
                    </SelectContent>
                </Select>
                <InputError message={error('tank_id')} />
            </td>
            <td className="px-1.5 py-2.5 align-top">
                <LockedBox
                    className="w-[6rem]"
                    label={t('pump_counters.previous')}
                    value={
                        shown.previous !== null
                            ? formatNumber(shown.previous, 0)
                            : '—'
                    }
                    data-test="previous-box"
                />
            </td>
            <td className="px-1.5 py-2.5 align-top">
                <div className="w-full min-w-[6.5rem]">
                    {/* Whole counter values, shown grouped (2,589,386); typed with or without commas. */}
                    <MoneyInput
                        inputMode="numeric"
                        dir="ltr"
                        className={cn(inputClass, 'text-end tabular-nums')}
                        value={row.reading_value}
                        onChange={(raw) =>
                            edit('reading_value', raw.split('.')[0])
                        }
                        onBlur={() => check('reading_value')}
                        data-test="reading-input"
                    />
                    <InputError message={readingMessage} />
                </div>
            </td>
            <td className="px-1.5 py-2.5 align-top">
                <LockedBox
                    className={cn('w-[5rem]', soldTone)}
                    label={t('pump_counters.sold_quantity')}
                    value={
                        shown.sold !== null
                            ? `${formatNumber(shown.sold)} L`
                            : '—'
                    }
                    data-test="sold-box"
                />
            </td>
            <td className="w-20 px-1.5 py-2.5 align-top">
                <div className="w-full">
                    <Input
                        type="number"
                        step="0.001"
                        min="0"
                        className={inputClass}
                        value={row.governmental_liters}
                        onChange={(e) =>
                            edit('governmental_liters', e.target.value)
                        }
                        onBlur={() => check('governmental_liters')}
                    />
                    <InputError message={error('governmental_liters')} />
                </div>
            </td>
            <td className="w-20 px-1.5 py-2.5 align-top">
                <div className="w-full">
                    <Input
                        type="number"
                        step="0.001"
                        min="0"
                        className={inputClass}
                        value={row.return_liters}
                        onChange={(e) => edit('return_liters', e.target.value)}
                        onBlur={() => check('return_liters')}
                    />
                    <InputError message={deductedMessage} />
                </div>
            </td>
            <td className="px-1.5 py-2.5 align-top">
                <div className="w-full min-w-[4.5rem]">
                    <Input
                        type="text"
                        className={inputClass}
                        value={row.notes}
                        onChange={(e) => onChange({ notes: e.target.value })}
                    />
                </div>
            </td>
        </tr>
    );
}
