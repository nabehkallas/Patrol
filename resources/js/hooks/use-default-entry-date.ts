import { usePage } from '@inertiajs/react';
import { todayInStation } from '@/lib/format';
import type { Auth } from '@/types';

/**
 * The date a new-entry form (pump readings, cash transactions, debts, inventory, ...) should
 * default to, honoring the signed-in user's "Default Data Entry Date" preference (Settings ->
 * General Preferences): 'today' (the normal case) or 'yesterday' (for closing out a previous
 * day's shift after midnight). Always returns a real, still-editable date string -- this only
 * changes the initial value a date input opens with.
 */
export function useDefaultEntryDate(): string {
    const { auth } = usePage<{ auth: Auth }>().props;

    // Station time, not the device's own clock or UTC -- see todayInStation().
    return todayInStation(
        auth.user?.default_entry_date === 'yesterday' ? -1 : 0,
    );
}
