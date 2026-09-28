import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatCurrencyAmount, formatSyp } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Currency, CurrencyBreakdown } from '@/types';
import type { SummaryCardAccent } from './summary-card';

/** Every currency in the breakdown besides SYP (SYP is always shown as the card's main figure). */
function extraCurrencies(breakdown: CurrencyBreakdown): [Currency, number][] {
    return Object.entries(breakdown).filter(
        ([currency]) => currency !== 'SYP',
    ) as [Currency, number][];
}

// Same accent->role mapping as SummaryCard (resources/js/components/summary-card.tsx) --
// CurrencyCard has its own layout (a multi-currency breakdown list SummaryCard doesn't support)
// so it applies the same semantic color classes directly rather than wrapping SummaryCard.
const ACCENT_CLASSES: Record<
    SummaryCardAccent,
    { card: string; value: string }
> = {
    orange: {
        card: 'bg-warning-soft border-warning-border',
        value: 'text-warning-accent',
    },
    green: {
        card: 'bg-success-soft border-success-border',
        value: 'text-success-accent',
    },
    red: {
        card: 'bg-destructive-soft border-destructive-border',
        value: 'text-destructive-accent',
    },
    blue: {
        card: 'bg-info-soft border-info-border',
        value: 'text-info-accent',
    },
};

export function CurrencyCard({
    label,
    breakdown,
    accent,
    extraContent,
}: {
    label: string;
    breakdown: CurrencyBreakdown;
    accent?: SummaryCardAccent;
    extraContent?: ReactNode;
}) {
    const extras = extraCurrencies(breakdown);
    const tokens = accent ? ACCENT_CLASSES[accent] : null;

    return (
        <Card className={tokens?.card}>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className={cn('text-2xl', tokens?.value)}>
                    {formatSyp(breakdown.SYP)}
                </CardTitle>
            </CardHeader>
            {(extras.length > 0 || extraContent) && (
                <CardContent className="space-y-1">
                    {extras.map(([currency, amount]) => (
                        <div
                            key={currency}
                            className="flex items-center justify-between text-sm"
                        >
                            <span className="text-muted-foreground">
                                {currency}
                            </span>
                            <span className="font-medium">
                                {formatCurrencyAmount(amount, currency)}
                            </span>
                        </div>
                    ))}
                    {extraContent}
                </CardContent>
            )}
        </Card>
    );
}
