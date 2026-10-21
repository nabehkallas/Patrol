import { formatDate } from '@/lib/format';
import type { ShownDate as ShownDateValue } from '@/types';

/**
 * What a list shows for an entry, on one line: the date it counts for ("20 Oct"; a reading's or
 * fuel sale's is its reading date), with the shift on a station with several ("20 Oct · Morning").
 * The year shows only when it isn't the current one. No time: when it was saved, and by whom,
 * is in the audit log.
 */
export function ShownDate({ date, shift }: ShownDateValue) {
    return (
        <span className="whitespace-nowrap">
            {formatDate(date.slice(0, 10), true)}
            {shift && ` · ${shift}`}
        </span>
    );
}
