import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { todayInStation } from '@/lib/format';
import type { Auth } from '@/types';

/** How far back anyone but an admin may date an entry (App\Rules\EntryDate::BACKDATE_DAYS). */
export const BACKDATE_DAYS = 7;

/**
 * The date input for the day a record is entered for: never in the future, and for anyone but
 * an admin at most BACKDATE_DAYS back. The server checks the same (App\Rules\EntryDate).
 */
export function EntryDateInput(props: ComponentProps<typeof Input>) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <Input
            type="date"
            max={todayInStation()}
            min={auth.isAdmin ? undefined : todayInStation(-BACKDATE_DAYS)}
            {...props}
        />
    );
}
