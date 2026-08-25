import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type DateRangePickerProps = {
    from: string;
    to: string;
    onChange: (range: { from: string; to: string }) => void;
    className?: string;
};

/** Parses a plain 'YYYY-MM-DD' string as a local date, avoiding the UTC-midnight shift
 * `new Date('YYYY-MM-DD')` applies (which can render as the previous day west of UTC). */
function parseDateOnly(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function formatDateOnly(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/**
 * One popover calendar for picking a from/to date range — click a day to start, click
 * another to extend it, or just click once and stop for a single-day range.
 */
export function DateRangePicker({
    from,
    to,
    onChange,
    className,
}: DateRangePickerProps) {
    const [open, setOpen] = useState(false);

    const selected: DateRange = {
        from: parseDateOnly(from),
        to: parseDateOnly(to),
    };

    function handleSelect(range: DateRange | undefined) {
        if (!range?.from) {
            return;
        }

        const nextFrom = formatDateOnly(range.from);
        const nextTo = range.to ? formatDateOnly(range.to) : nextFrom;

        onChange({ from: nextFrom, to: nextTo });

        if (range.to) {
            setOpen(false);
        }
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn('font-normal', className)}
                >
                    <CalendarIcon />
                    {formatDate(from)} – {formatDate(to)}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                    mode="range"
                    defaultMonth={selected.from}
                    selected={selected}
                    onSelect={handleSelect}
                />
            </PopoverContent>
        </Popover>
    );
}
