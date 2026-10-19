import { formatDayMonthYear } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** A counter reading's business day ('YYYY-MM-DD') and when it was typed in (ISO timestamp). */
export type EntryStamp = { date: string; recorded_at: string | null };

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
