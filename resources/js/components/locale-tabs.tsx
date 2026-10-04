import type { HTMLAttributes } from 'react';
import { LOCALES, SUPPORTED_LOCALES, useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

export default function LocaleToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { locale, updateLocale } = useLocale();

    return (
        <div
            role="radiogroup"
            className={cn(
                'inline-flex flex-wrap gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800',
                className,
            )}
            {...props}
        >
            {SUPPORTED_LOCALES.map((value) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={locale === value}
                    lang={value}
                    dir={LOCALES[value].dir}
                    onClick={() => updateLocale(value)}
                    data-test={`locale-${value}`}
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        locale === value
                            ? 'shadow-xs bg-white dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {LOCALES[value].name}
                </button>
            ))}
        </div>
    );
}
