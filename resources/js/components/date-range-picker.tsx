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
 *
 * The calendar always sees the committed from/to as an already-complete range, so without
 * tracking the in-progress pick separately, every click would be read as "extend this
 * existing range" (react-day-picker's own range logic) — which turns a single click into an
 * immediately-complete two-endpoint range and closes right away. `pending` holds the
 * selection while it's still being made, reset to nothing each time the popover opens, so the
 * first click always starts a fresh range instead of extending the old one.
 */
export function DateRangePicker({
    from,
    to,
    onChange,
    className,
}: DateRangePickerProps) {
    const [open, setOpen] = useState(false);
    const [pending, setPending] = useState<DateRange | undefined>(undefined);

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPending(undefined);
        }

        setOpen(nextOpen);
    }

    function handleSelect(range: DateRange | undefined) {
        setPending(range);

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

    const displayed: DateRange = pending ?? {
        from: parseDateOnly(from),
        to: parseDateOnly(to),
    };

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
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
                    resetOnSelect
                    defaultMonth={displayed.from}
                    selected={displayed}
                    onSelect={handleSelect}
                />
            </PopoverContent>
        </Popover>
    );
}
