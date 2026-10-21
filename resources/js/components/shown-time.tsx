import { formatDate, formatDateTime, formatShortDateTime } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { ShownTime as ShownTimeValue } from '@/types';

type Props = ShownTimeValue & {
    /** Who recorded it, in the muted line under the time. */
    by?: string | null;
    /** Leave out the year when it is the current one (a narrow column). */
    omitCurrentYear?: boolean;
};

/**
 * When a record shows as having happened (Transaction::shownTime()): its date and time, or for
 * a fuel sale or reading entered on a later day than its own, its date with when it was entered
 * (and its shift, on a station with several) in a small muted line under it.
 */
export function ShownTime({ at, entered, by, omitCurrentYear = false }: Props) {
    const { t } = useTranslation();

    const enteredText = entered
        ? t('transactions.entered', {
              time: [formatShortDateTime(entered.at), entered.shift]
                  .filter(Boolean)
                  .join(' · '),
          })
        : null;
    const muted = [enteredText, by].filter(Boolean).join(' · ');

    return (
        <>
            {/* The muted line wraps only where the cell allows it. */}
            <span className="whitespace-nowrap">
                {entered
                    ? formatDate(at, omitCurrentYear)
                    : formatDateTime(at, omitCurrentYear)}
            </span>
            {muted && (
                <span className="text-muted-foreground block text-xs">
                    {muted}
                </span>
            )}
        </>
    );
}
