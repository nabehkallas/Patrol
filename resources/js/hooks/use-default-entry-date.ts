import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

/** Local calendar date as 'YYYY-MM-DD', avoiding the UTC-midnight shift `toISOString()` can
 * introduce (which reads as the wrong day west of UTC near midnight). */
function toDateOnlyString(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/**
 * The date a new-entry form (pump readings, cash transactions, debts, inventory, ...) should
 * default to, honoring the signed-in user's "Default Data Entry Date" preference (Settings ->
 * General Preferences): 'today' (the normal case) or 'yesterday' (for closing out a previous
 * day's shift after midnight). Always returns a real, still-editable date string -- this only
 * changes the initial value a date input opens with.
 */
export function useDefaultEntryDate(): string {
    const { auth } = usePage<{ auth: Auth }>().props;

    const date = new Date();

    if (auth.user?.default_entry_date === 'yesterday') {
        date.setDate(date.getDate() - 1);
    }

    return toDateOnlyString(date);
}
