import type { HTMLAttributes } from 'react';
import type { Locale } from '@/hooks/use-locale';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

export default function LocaleToggleTab({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { locale, updateLocale } = useLocale();

    const tabs: { value: Locale; label: string }[] = [
        { value: 'en', label: 'English' },
        { value: 'ar', label: 'العربية' },
    ];

    return (
        <div
            className={cn('inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800', className)}
            {...props}
        >
            {tabs.map(({ value, label }) => (
                <button
                    key={value}
                    onClick={() => updateLocale(value)}
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        locale === value
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
