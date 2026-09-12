import { router, usePage } from '@inertiajs/react';
import type { HTMLAttributes } from 'react';
import PreferencesController from '@/actions/App/Http/Controllers/Settings/PreferencesController';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types';

type EntryDateOption = 'today' | 'yesterday';

export default function DefaultEntryDateTabs({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();

    const current: EntryDateOption = auth.user.default_entry_date ?? 'today';

    const tabs: { value: EntryDateOption; label: string }[] = [
        { value: 'today', label: t('settings.preferences.today') },
        { value: 'yesterday', label: t('settings.preferences.yesterday') },
    ];

    function select(value: EntryDateOption) {
        router.patch(
            PreferencesController.update.url(),
            { default_entry_date: value },
            { preserveScroll: true },
        );
    }

    return (
        <div
            className={cn(
                'inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800',
                className,
            )}
            {...props}
        >
            {tabs.map(({ value, label }) => (
                <button
                    key={value}
                    type="button"
                    onClick={() => select(value)}
                    className={cn(
                        'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        current === value
                            ? 'shadow-xs bg-white dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
