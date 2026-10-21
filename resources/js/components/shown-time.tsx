import { formatDate, formatDateTime, formatShortDateTime } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { ShownTime as ShownTimeValue } from '@/types';

/**
 * When a record shows as having happened (Transaction::shownTime()): its date and time, or for
 * a fuel sale entered on a later day than its reading's, the reading's date with when it was
 * entered (and its shift, on a station with several) in a small muted line under it.
 */
export function ShownTime({ at, entered }: ShownTimeValue) {
    const { t } = useTranslation();

    if (!entered) {
        return <>{formatDateTime(at)}</>;
    }

    const time = [formatShortDateTime(entered.at), entered.shift]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            {formatDate(at)}
            <span className="text-muted-foreground block text-xs">
                {t('transactions.entered', { time })}
            </span>
        </>
    );
}
