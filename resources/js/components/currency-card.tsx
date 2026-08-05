import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatCurrencyAmount, formatSyp } from '@/lib/format';
import type { Currency, CurrencyBreakdown } from '@/types';

/** Every currency in the breakdown besides SYP (SYP is always shown as the card's main figure). */
function extraCurrencies(breakdown: CurrencyBreakdown): [Currency, number][] {
    return Object.entries(breakdown).filter(
        ([currency]) => currency !== 'SYP',
    ) as [Currency, number][];
}

export function CurrencyCard({
    label,
    breakdown,
    extraContent,
}: {
    label: string;
    breakdown: CurrencyBreakdown;
    extraContent?: ReactNode;
}) {
    const extras = extraCurrencies(breakdown);

    return (
        <Card>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl">
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
