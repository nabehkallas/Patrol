import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
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
import type { EntryStamp } from './last-entry';

export type EntryTank = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    is_active: boolean;
};

/** One pump on the day being entered: the readings around it, and its saved reading that day. */
export type EntryPump = {
    pump_id: number;
    previous: (EntryStamp & { reading_value: number }) | null;
    next: { reading_value: number; date: string } | null;
    recorded: {
        id: number;
        reading_value: number;
        tank_id: number | null;
        governmental_liters: number | null;
        return_liters: number | null;
        notes: string | null;
    } | null;
};

/** A row of the entry table: a new reading (id null) or a saved one prefilled on a past day. */
export type EntryRowData = {
    id: number | null;
    pump_id: number;
    tank_id: string;
    reading_value: string;
    governmental_liters: string;
    return_liters: string;
    notes: string;
};

const INPUT =
    'w-full border-slate-300 dark:border-slate-700 dark:bg-slate-800/80';

type Props = {
    row: EntryRowData;
    rowIndex: number;
    pumpName: string;
    pumpTitle: string;
    badgeClass: string;
    rowBorderClass: string;
    tankOptions: EntryTank[];
    context: EntryPump | undefined;
    /** A past day: missed pumps are marked, saved ones show a check. */
    isPastDay: boolean;
    /** Saved readings are changed by admins only, as on the edit page. */
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
    const saved = row.id !== null;
    const readOnly = saved && !canEditSaved;
    const missing = isPastDay && !saved;
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
            <td className="px-3 py-2.5 align-top">
                <div className="flex min-h-9 items-center justify-center">
                    {/* The check sits on the badge's corner, so it adds no width to the column. */}
                    <span className="relative inline-flex max-w-full">
                        <span
                            className={cn(
                                'inline-block max-w-[10rem] truncate whitespace-nowrap rounded-md px-2.5 py-1 text-center text-base font-semibold leading-6 ring-1 ring-inset [unicode-bidi:plaintext]',
                                badgeClass,
                            )}
                            title={pumpTitle}
                        >
                            {pumpName}
                        </span>
                        {isPastDay && saved && (
                            <CheckCircle2
                                className="bg-card absolute -end-1.5 -top-1.5 size-3.5 rounded-full text-emerald-600/80 dark:text-emerald-400/80"
                                aria-label={t('pump_counters.recorded_on_day')}
                                data-test="saved-check"
                            />
                        )}
                    </span>
                </div>
            </td>
            <td className="px-3 py-2.5 align-top">
                <Select
                    value={row.tank_id}
                    disabled={readOnly}
                    onValueChange={(tankId) => onChange({ tank_id: tankId })}
                >
                    <SelectTrigger className="w-full min-w-[9rem] max-w-[13rem] border-slate-300 dark:border-slate-700 dark:bg-slate-800/80">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <TankSelectItems tanks={tankOptions} />
                    </SelectContent>
                </Select>
                <InputError message={error('tank_id')} />
            </td>
            <td className="px-3 py-2.5 align-top">
                <div className="w-full min-w-[6rem]">
                    {/* Whole counter values, shown grouped (2,589,386); typed with or without commas. */}
                    <MoneyInput
                        inputMode="numeric"
                        dir="ltr"
                        className={cn(inputClass, 'text-end tabular-nums')}
                        value={row.reading_value}
                        readOnly={readOnly}
                        onChange={(raw) =>
                            edit('reading_value', raw.split('.')[0])
                        }
                        onBlur={() => check('reading_value')}
                        data-test="reading-input"
                    />
                    <InputError message={readingMessage} />
                </div>
            </td>
            <td className="px-3 py-2.5 align-top">
                <div className="w-full min-w-[5rem]">
                    <Input
                        type="number"
                        step="0.001"
                        min="0"
                        className={inputClass}
                        value={row.governmental_liters}
                        readOnly={readOnly}
                        onChange={(e) =>
                            edit('governmental_liters', e.target.value)
                        }
                        onBlur={() => check('governmental_liters')}
                    />
                    <InputError message={error('governmental_liters')} />
                </div>
            </td>
            <td className="px-3 py-2.5 align-top">
                <div className="w-full min-w-[5rem]">
                    <Input
                        type="number"
                        step="0.001"
                        min="0"
                        className={inputClass}
                        value={row.return_liters}
                        readOnly={readOnly}
                        onChange={(e) => edit('return_liters', e.target.value)}
                        onBlur={() => check('return_liters')}
                    />
                    <InputError message={deductedMessage} />
                </div>
            </td>
            <td className="px-3 py-2.5 align-top">
                <div className="w-full min-w-[6rem]">
                    <Input
                        type="text"
                        className={inputClass}
                        value={row.notes}
                        readOnly={readOnly}
                        onChange={(e) => onChange({ notes: e.target.value })}
                    />
                </div>
            </td>
        </tr>
    );
}
