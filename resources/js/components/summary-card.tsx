import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * The app's global semantic card-color system: the same four meanings everywhere a summary/KPI
 * card appears, across every page -- orange for fuel/current-important values, green for
 * revenue/positive, red for expenses/warnings, blue for neutral/opening-balance-type info. Each
 * maps to one of the theme's existing semantic CSS roles (orange->warning, green->success,
 * red->destructive, blue->info -- see resources/css/app.css), the same roles Badge and a few
 * other components already draw from, so a color always means the same thing everywhere, not
 * just on cards.
 */
export type SummaryCardAccent = 'orange' | 'green' | 'red' | 'blue';

type AccentTokens = {
    card: string;
    icon: string;
    value: string;
    label: string;
};

// Every class name below must appear as a literal string (not built via template
// interpolation) so Tailwind's build-time scanner actually generates the CSS for it.
const ACCENT_TOKENS: Record<SummaryCardAccent, AccentTokens> = {
    orange: {
        card: 'bg-warning-soft border-warning-border',
        icon: 'bg-warning text-warning-foreground',
        value: 'text-warning-accent',
        label: 'text-warning-soft-foreground',
    },
    green: {
        card: 'bg-success-soft border-success-border',
        icon: 'bg-success text-success-foreground',
        value: 'text-success-accent',
        label: 'text-success-soft-foreground',
    },
    red: {
        card: 'bg-destructive-soft border-destructive-border',
        icon: 'bg-destructive text-destructive-foreground',
        value: 'text-destructive-accent',
        label: 'text-destructive-soft-foreground',
    },
    blue: {
        card: 'bg-info-soft border-info-border',
        icon: 'bg-info text-info-foreground',
        value: 'text-info-accent',
        label: 'text-info-soft-foreground',
    },
};

export function SummaryCard({
    accent,
    icon,
    label,
    value,
    subtitle,
    breakdown,
    breakdownLabel,
    children,
}: {
    accent: SummaryCardAccent;
    icon: ReactNode;
    label: string;
    value: ReactNode;
    subtitle?: string;
    breakdown?: ReactNode;
    breakdownLabel?: string;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const tokens = ACCENT_TOKENS[accent];

    return (
        <Card className={cn('overflow-hidden py-0', tokens.card)}>
            <CardContent className="space-y-3 pt-5">
                <div className="flex items-start justify-between gap-3">
                    <div
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-lg',
                            tokens.icon,
                        )}
                    >
                        {icon}
                    </div>
                    {breakdown && (
                        <Popover>
                            <PopoverTrigger asChild>
                                <button
                                    type="button"
                                    className="text-muted-foreground hover:text-foreground text-xs underline decoration-dotted underline-offset-4"
                                >
                                    {t('cash_box.view_breakdown')}
                                </button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="end"
                                className="max-h-96 w-80 overflow-auto"
                            >
                                <p className="mb-2 text-sm font-semibold">
                                    {breakdownLabel}
                                </p>
                                <div className="space-y-2">{breakdown}</div>
                            </PopoverContent>
                        </Popover>
                    )}
                </div>

                <div>
                    <p className={cn('text-sm', tokens.label)}>{label}</p>
                    <p className={cn('text-2xl font-bold', tokens.value)}>
                        {value}
                    </p>
                    {subtitle && (
                        <p className={cn('mt-0.5 text-xs', tokens.label)}>
                            {subtitle}
                        </p>
                    )}
                </div>

                {children}
            </CardContent>
        </Card>
    );
}
