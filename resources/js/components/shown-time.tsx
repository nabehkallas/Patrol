import { formatDateTime } from '@/lib/format';
import type { ShownTime as ShownTimeValue } from '@/types';

type Props = ShownTimeValue & {
    /** Shown on hover, e.g. who recorded it. */
    title?: string | null;
};

/**
 * When a record was saved (Transaction::savedAt()), on one line: "21 Oct, 02:19", with the
 * shift on a station with several ("21 Oct, 02:19 · Morning"). The year shows only when it
 * isn't the current one. Display only: the record still counts on its own date.
 */
export function ShownTime({ at, shift, title }: Props) {
    return (
        <span className="whitespace-nowrap" title={title ?? undefined}>
            {formatDateTime(at, true)}
            {shift && ` · ${shift}`}
        </span>
    );
}
