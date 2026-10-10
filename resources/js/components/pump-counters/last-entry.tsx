import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    formatDayMonthYear,
    formatNumber,
    formatTime24,
    stationDateOf,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** A counter reading's business day ('YYYY-MM-DD') and when it was typed in (ISO timestamp). */
export type EntryStamp = { date: string; recorded_at: string | null };

/**
 * "DD/MM/YYYY - HH:MM" for a reading: the day it counts for, plus the time it was entered when
 * that happened on the same day. A reading entered on a later day (yesterday's shift typed in
 * this morning) gets the actual entry date and time as a second line instead of a mixed stamp.
 */
function stampLines(entry: EntryStamp): {
    day: string;
    entered: string | null;
} {
    if (!entry.recorded_at) {
        return { day: formatDayMonthYear(entry.date), entered: null };
    }

    const enteredAt = `${formatDayMonthYear(entry.recorded_at)} - ${formatTime24(entry.recorded_at)}`;

    return stationDateOf(entry.recorded_at) === entry.date
        ? { day: enteredAt, entered: null }
        : { day: formatDayMonthYear(entry.date), entered: enteredAt };
}

/** "Previous reading: 1,234,567" under a pump's counter input; its date shows on hover. */
export function PreviousReading({
    value,
    entry,
}: {
    value: number;
    entry: EntryStamp;
}) {
    const { t } = useTranslation();
    const { day, entered } = stampLines(entry);

    return (
        // Not hoverable and not focusable: it closes the moment the pointer leaves the label,
        // opens below it (never over the counter input above), and lets clicks pass through.
        <Tooltip disableHoverableContent>
            <TooltipTrigger asChild>
                <p
                    className="mt-1 whitespace-nowrap text-sm font-medium text-gray-600 dark:text-gray-300"
                    data-test="previous-reading"
                >
                    {t('pump_counters.previous')}: {formatNumber(value, 0)}
                </p>
            </TooltipTrigger>
            <TooltipContent
                side="bottom"
                className="data-[state=closed]:animate-none! pointer-events-none"
                data-test="previous-reading-tooltip"
            >
                <div>
                    {t('pump_counters.last_entry_pump')}:{' '}
                    <bdi dir="ltr">{day}</bdi>
                </div>
                {entered && (
                    <div className="opacity-80">
                        {t('pump_counters.entered_at')}:{' '}
                        <bdi dir="ltr">{entered}</bdi>
                    </div>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * "Last entry: DD/MM/YYYY" -- a read-only note at the far end of the entry header (opposite the
 * date field), styled as a quiet outline badge so it isn't mistaken for a control.
 */
export function SystemLastEntry({
    entry,
    className,
}: {
    entry: EntryStamp | null;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-block max-w-full select-none truncate rounded-full border border-slate-300/80 bg-transparent px-2.5 py-0.5 text-xs text-slate-500 dark:border-slate-600 dark:text-slate-400',
                className,
            )}
            data-test="system-last-entry"
        >
            {t('pump_counters.last_entry_system')}:{' '}
            <bdi
                dir="ltr"
                className="font-medium tabular-nums text-slate-600 dark:text-slate-300"
            >
                {entry
                    ? formatDayMonthYear(entry.date)
                    : t('pump_counters.no_entries_yet')}
            </bdi>
        </span>
    );
}
