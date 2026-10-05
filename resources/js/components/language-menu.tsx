import { Check, ChevronDown, Globe } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LOCALES, SUPPORTED_LOCALES, useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

/**
 * The one language picker used across the app: a globe and the current language's name,
 * opening a scrollable list of every supported language.
 *
 * - `ghost` (default): a bare button for headers (platform bar, landing page).
 * - `field`: a bordered control that matches the app's selects, for forms such as Settings.
 */
export function LanguageMenu({
    variant = 'ghost',
    className,
    contentClassName,
    align = 'end',
    'data-test': dataTest,
}: {
    variant?: 'ghost' | 'field';
    className?: string;
    /** Extra classes for the open list, e.g. the always-dark landing page's own colours. */
    contentClassName?: string;
    align?: 'start' | 'end';
    'data-test'?: string;
}) {
    const { locale, updateLocale } = useLocale();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'focus-visible:ring-ring/50 inline-flex items-center gap-2 whitespace-nowrap text-sm outline-none focus-visible:ring-[3px]',
                        variant === 'field'
                            ? 'border-input bg-background hover:bg-accent/50 dark:bg-input/30 shadow-xs h-9 w-56 justify-between rounded-md border px-3'
                            : 'rounded-lg px-2.5 py-2',
                        className,
                    )}
                    data-test={dataTest}
                >
                    <span className="inline-flex min-w-0 items-center gap-2">
                        <Globe className="size-4 shrink-0 opacity-70" />
                        <bdi lang={locale} className="truncate">
                            {LOCALES[locale].name}
                        </bdi>
                    </span>
                    <ChevronDown className="size-4 shrink-0 opacity-50" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align={align}
                className={cn(
                    'max-h-[min(24rem,var(--radix-dropdown-menu-content-available-height))] min-w-56 overflow-y-auto p-1',
                    contentClassName,
                )}
                data-test="language-menu"
            >
                {SUPPORTED_LOCALES.map((value) => (
                    <DropdownMenuItem
                        key={value}
                        onSelect={() => updateLocale(value)}
                        aria-current={value === locale ? 'true' : undefined}
                        data-test={`language-${value}`}
                        className={cn(
                            'cursor-pointer justify-between gap-4 px-3 py-2',
                            value === locale && 'bg-accent/60 font-medium',
                        )}
                    >
                        <bdi lang={value}>{LOCALES[value].name}</bdi>
                        {value === locale && (
                            <Check className="text-primary size-4" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
