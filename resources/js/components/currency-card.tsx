import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    formatCurrencyAmount,
    formatPrimary,
    getPrimaryCurrency,
} from '@/lib/format';
import type { Currency, CurrencyBreakdown } from '@/types';

/** Every currency besides the primary one (shown as the card's main figure). */
function extraCurrencies(breakdown: CurrencyBreakdown): [Currency, number][] {
    return Object.entries(breakdown).filter(
        ([currency]) => currency !== getPrimaryCurrency(),
    ) as [Currency, number][];
}

const ACCENT_BORDERS = {
    green: 'border-t-4 border-t-green-500',
    red: 'border-t-4 border-t-red-500',
};

export function CurrencyCard({
    label,
    breakdown,
    accent,
    extraContent,
}: {
    label: string;
    breakdown: CurrencyBreakdown;
    accent?: keyof typeof ACCENT_BORDERS;
    extraContent?: ReactNode;
}) {
    const extras = extraCurrencies(breakdown);

    return (
        <Card className={accent ? ACCENT_BORDERS[accent] : undefined}>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl font-bold">
                    {formatPrimary(breakdown[getPrimaryCurrency()] ?? 0)}
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
