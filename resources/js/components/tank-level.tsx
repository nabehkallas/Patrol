import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

/** How full a tank is: a bar and the litres with their share of capacity; red below zero. */
export function TankLevel({
    liters,
    capacity,
}: {
    liters: number;
    capacity: number;
}) {
    const percent =
        capacity > 0 ? Math.round((Math.max(liters, 0) / capacity) * 100) : 0;
    const negative = liters < 0;

    return (
        <div className="min-w-36 space-y-1">
            <div className="bg-muted h-2 overflow-hidden rounded-full">
                <div
                    className={cn(
                        'h-full rounded-full',
                        negative
                            ? 'bg-destructive'
                            : percent < 15
                              ? 'bg-amber-500'
                              : 'bg-emerald-500',
                    )}
                    style={{
                        width: `${negative ? 100 : Math.min(percent, 100)}%`,
                    }}
                />
            </div>
            <div
                className={cn(
                    'text-xs',
                    negative
                        ? 'text-destructive font-medium'
                        : 'text-muted-foreground',
                )}
            >
                <bdi dir="ltr">{formatNumber(liters)} L</bdi>
                {!negative && ` · ${percent}%`}
            </div>
        </div>
    );
}
