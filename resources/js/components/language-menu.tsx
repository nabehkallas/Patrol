import { Check, Languages } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LOCALES, SUPPORTED_LOCALES, useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

/** A compact language picker for headers: the current language's name, opening the full list. */
export function LanguageMenu({
    className,
    'data-test': dataTest,
}: {
    className?: string;
    'data-test'?: string;
}) {
    const { locale, updateLocale } = useLocale();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm',
                        className,
                    )}
                    data-test={dataTest}
                >
                    <Languages className="size-4" />
                    {LOCALES[locale].name}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                {SUPPORTED_LOCALES.map((value) => (
                    <DropdownMenuItem
                        key={value}
                        onSelect={() => updateLocale(value)}
                        lang={value}
                        data-test={`language-${value}`}
                        className="justify-between gap-4"
                    >
                        {LOCALES[value].name}
                        {value === locale && <Check className="size-4" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
