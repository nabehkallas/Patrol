import { CheckIcon, ChevronsUpDownIcon, SearchIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type TimezoneOption = { value: string; offset: string };

/** Searchable dropdown over every standard timezone, matching on name or UTC offset. */
export function TimezoneSelect({
    value,
    options,
    onChange,
    disabled = false,
}: {
    value: string;
    options: TimezoneOption[];
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const selected = options.find((option) => option.value === value);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase().replace(/\s+/g, '_');

        if (needle === '') {
            return options;
        }

        return options.filter(
            (option) =>
                option.value.toLowerCase().includes(needle) ||
                option.offset.toLowerCase().includes(needle),
        );
    }, [options, query]);

    function choose(next: string) {
        setOpen(false);
        setQuery('');

        if (next !== value) {
            onChange(next);
        }
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className="w-full max-w-sm justify-between font-normal"
                >
                    <span dir="ltr" className="truncate">
                        {selected
                            ? `${selected.value} (${selected.offset})`
                            : value}
                    </span>
                    <ChevronsUpDownIcon className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <div className="flex items-center gap-2 border-b px-3">
                    <SearchIcon className="size-4 shrink-0 opacity-50" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && filtered.length > 0) {
                                event.preventDefault();
                                choose(filtered[0].value);
                            }
                        }}
                        placeholder={t('settings.preferences.timezone_search')}
                        className="placeholder:text-muted-foreground h-10 w-full bg-transparent text-sm outline-none"
                    />
                </div>
                <div className="max-h-72 overflow-y-auto p-1">
                    {filtered.length === 0 && (
                        <p className="text-muted-foreground px-2 py-6 text-center text-sm">
                            {t('settings.preferences.timezone_none')}
                        </p>
                    )}
                    {filtered.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => choose(option.value)}
                            className={cn(
                                'hover:bg-highlight hover:text-highlight-foreground focus-visible:bg-highlight focus-visible:text-highlight-foreground group flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm outline-none',
                                option.value === value && 'bg-accent/60',
                            )}
                        >
                            <CheckIcon
                                className={cn(
                                    'size-4 shrink-0',
                                    option.value === value
                                        ? 'opacity-100'
                                        : 'opacity-0',
                                )}
                            />
                            <bdi
                                dir="ltr"
                                className="flex-1 truncate text-start"
                            >
                                {option.value}
                            </bdi>
                            <bdi
                                dir="ltr"
                                className="text-muted-foreground group-hover:text-highlight-foreground group-focus-visible:text-highlight-foreground text-xs tabular-nums"
                            >
                                {option.offset}
                            </bdi>
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
