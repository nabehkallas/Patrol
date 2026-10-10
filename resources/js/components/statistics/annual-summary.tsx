import { router } from '@inertiajs/react';
import { LockIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Locale } from '@/hooks/use-locale';
import { LOCALES, useLocale } from '@/hooks/use-locale';
import { formatCurrencyAmount, formatNumber } from '@/lib/format';
import { useFuelColors } from '@/lib/fuel-colors';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index } from '@/routes/statistics';
import { lock } from '@/routes/statistics/annual';

export type AnnualMonth = {
    month: number;
    liters: number;
    fuel_revenue: number;
    store_revenue: number;
    other_revenue: number;
    revenue: number;
    expenses: number;
    sadcop: number;
};

/** One currency's year: every amount in that currency only (nothing is converted). */
export type AnnualCurrencyData = {
    months: AnnualMonth[];
    totals: AnnualMonth;
    peak_revenue: { month: number; value: number } | null;
    peak_expense: { month: number; value: number } | null;
    has_other_revenue: boolean;
};

export type AnnualSummaryData = {
    year: number;
    years: number[];
    /** The primary currency first, then any other currency the year has money in. */
    currencies: Record<string, AnnualCurrencyData>;
    liters_by_fuel_type: { name: string; liters: number; percent: number }[];
};

const REVENUE_COLOR = '#10b981'; // emerald-500
const EXPENSE_COLOR = '#f43f5e'; // rose-500

/** Month names in the UI language; ar-SY gives the Levantine names (كانون الثاني, شباط...). */
function monthName(month: number, locale: Locale, style: 'long' | 'short') {
    return new Date(Date.UTC(2000, month - 1, 1)).toLocaleDateString(
        LOCALES[locale].intl,
        { month: style, timeZone: 'UTC' },
    );
}

/** Short axis labels such as 1.2M / 350K, so the Y axis stays narrow. */
function compact(value: number) {
    return new Intl.NumberFormat('en-US', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

function HighlightCard({
    label,
    accent,
    value,
    detail,
}: {
    label: string;
    accent: string;
    value: string;
    detail: React.ReactNode;
}) {
    return (
        <Card className={cn('border-t-4', accent)}>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl font-bold">{value}</CardTitle>
                <div className="text-muted-foreground text-sm">{detail}</div>
            </CardHeader>
        </Card>
    );
}

export function AnnualSummary({ data }: { data: AnnualSummaryData }) {
    const { t, locale } = useTranslation();
    const fuelColors = useFuelColors();
    const { direction } = useLocale();
    const codes = Object.keys(data.currencies);
    const [picked, setPicked] = useState(codes[0]);
    // The chosen currency, or the primary one if the newly picked year doesn't have it.
    const currency = picked in data.currencies ? picked : codes[0];
    const view = data.currencies[currency];
    const money = (value: number) => formatCurrencyAmount(value, currency);

    const chartData = view.months.map((row) => ({
        name: monthName(row.month, locale, 'short'),
        revenue: row.revenue,
        expenses: row.expenses,
    }));

    const hasData = view.months.some(
        (row) =>
            row.revenue > 0 ||
            row.expenses > 0 ||
            row.sadcop > 0 ||
            row.liters > 0,
    );

    const columns: { key: keyof AnnualMonth; label: string; liters?: true }[] =
        [
            { key: 'liters', label: t('statistics.col_liters'), liters: true },
            { key: 'fuel_revenue', label: t('statistics.col_fuel_revenue') },
            { key: 'store_revenue', label: t('statistics.col_store_revenue') },
            ...(view.has_other_revenue
                ? [
                      {
                          key: 'other_revenue' as const,
                          label: t('statistics.col_other_revenue'),
                      },
                  ]
                : []),
            {
                key: 'expenses',
                label: t('statistics.col_operational_expenses'),
            },
            { key: 'sadcop', label: t('statistics.col_sadcop') },
        ];

    const cell = (row: AnnualMonth, key: keyof AnnualMonth, liters?: true) =>
        liters ? `${formatNumber(row[key])} L` : money(row[key] as number);

    return (
        <div className="space-y-6">
            <Card className="py-4">
                <CardContent className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">
                            {t('statistics.year')}
                        </span>
                        <Select
                            value={String(data.year)}
                            onValueChange={(year) =>
                                router.get(
                                    index.url({
                                        query: { tab: 'annual', year },
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <SelectTrigger className="min-w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {data.years.map((year) => (
                                    <SelectItem key={year} value={String(year)}>
                                        {year}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {codes.length > 1 && (
                            <div
                                className="bg-muted inline-flex gap-1 rounded-lg p-1"
                                role="tablist"
                                aria-label={t('common.currency')}
                            >
                                {codes.map((code) => (
                                    <button
                                        key={code}
                                        type="button"
                                        role="tab"
                                        aria-selected={code === currency}
                                        onClick={() => setPicked(code)}
                                        className={cn(
                                            'rounded-md px-3 py-1 text-sm transition-colors',
                                            code === currency
                                                ? 'bg-background shadow-xs font-medium'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {code}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => router.post(lock.url())}
                    >
                        <LockIcon className="size-4" />
                        {t('statistics.annual_lock')}
                    </Button>
                </CardContent>
            </Card>

            <div className="card-grid">
                <HighlightCard
                    label={t('statistics.peak_revenue_month')}
                    accent="border-t-emerald-500"
                    value={
                        view.peak_revenue ? money(view.peak_revenue.value) : '—'
                    }
                    detail={
                        view.peak_revenue ? (
                            <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                {monthName(
                                    view.peak_revenue.month,
                                    locale,
                                    'long',
                                )}{' '}
                                {data.year}
                            </span>
                        ) : (
                            t('statistics.no_data_year')
                        )
                    }
                />
                <HighlightCard
                    label={t('statistics.peak_expense_month')}
                    accent="border-t-rose-500"
                    value={
                        view.peak_expense ? money(view.peak_expense.value) : '—'
                    }
                    detail={
                        view.peak_expense ? (
                            <span className="font-medium text-rose-600 dark:text-rose-400">
                                {monthName(
                                    view.peak_expense.month,
                                    locale,
                                    'long',
                                )}{' '}
                                {data.year}
                            </span>
                        ) : (
                            t('statistics.no_data_year')
                        )
                    }
                />
                <HighlightCard
                    label={t('statistics.annual_liters_sold')}
                    accent="border-t-slate-500"
                    value={`${formatNumber(view.totals.liters)} L`}
                    detail={
                        data.liters_by_fuel_type.length > 0 ? (
                            <span className="flex flex-wrap gap-x-4 gap-y-1">
                                {data.liters_by_fuel_type.map((fuel) => (
                                    <span
                                        key={fuel.name}
                                        className={cn(
                                            'font-medium',
                                            fuelColors.byName(fuel.name).text,
                                        )}
                                    >
                                        <bdi>{fuel.name}</bdi>{' '}
                                        <bdi>{`${formatNumber(fuel.percent)}%`}</bdi>
                                    </span>
                                ))}
                            </span>
                        ) : (
                            t('statistics.no_data_year')
                        )
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        {t('statistics.revenue_vs_expenses')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-80" dir="ltr">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={chartData}
                                margin={{
                                    top: 8,
                                    right: 8,
                                    left: 8,
                                    bottom: 0,
                                }}
                            >
                                <CartesianGrid
                                    strokeDasharray="3 3"
                                    vertical={false}
                                    className="stroke-border"
                                />
                                <XAxis
                                    dataKey="name"
                                    reversed={direction === 'rtl'}
                                    tick={{ fontSize: 12 }}
                                    tickLine={false}
                                    className="fill-muted-foreground"
                                />
                                <YAxis
                                    orientation={
                                        direction === 'rtl' ? 'right' : 'left'
                                    }
                                    tickFormatter={compact}
                                    tick={{ fontSize: 12 }}
                                    tickLine={false}
                                    axisLine={false}
                                    width={56}
                                />
                                <Tooltip
                                    cursor={{ fillOpacity: 0.08 }}
                                    formatter={(value) => money(Number(value))}
                                    contentStyle={{
                                        background: 'var(--popover)',
                                        borderColor: 'var(--border)',
                                        color: 'var(--popover-foreground)',
                                        borderRadius: 8,
                                    }}
                                />
                                <Legend />
                                <Bar
                                    dataKey="revenue"
                                    name={t('statistics.revenue')}
                                    fill={REVENUE_COLOR}
                                    radius={[4, 4, 0, 0]}
                                />
                                <Bar
                                    dataKey="expenses"
                                    name={t('statistics.expenses')}
                                    fill={EXPENSE_COLOR}
                                    radius={[4, 4, 0, 0]}
                                />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        {t('statistics.monthly_breakdown')}
                    </CardTitle>
                    {!hasData && (
                        <CardDescription>
                            {t('statistics.no_data_year')}
                        </CardDescription>
                    )}
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50">
                                    <th className="px-4 py-3 text-start">
                                        {t('statistics.col_month')}
                                    </th>
                                    {columns.map((column) => (
                                        <th
                                            key={column.key}
                                            className="px-4 py-3 text-end"
                                        >
                                            {column.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {view.months.map((row) => (
                                    <tr key={row.month} className="border-t">
                                        <td className="px-4 py-2.5 text-start font-medium">
                                            {monthName(
                                                row.month,
                                                locale,
                                                'long',
                                            )}
                                        </td>
                                        {columns.map((column) => (
                                            <td
                                                key={column.key}
                                                className={cn(
                                                    'px-4 py-2.5 text-end tabular-nums',
                                                    row[column.key] === 0 &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                {cell(
                                                    row,
                                                    column.key,
                                                    column.liters,
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="bg-muted sticky bottom-0">
                                <tr className="border-t-2 font-bold">
                                    <td className="px-4 py-3 text-start">
                                        {t('statistics.annual_total')}
                                    </td>
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className="px-4 py-3 text-end tabular-nums"
                                        >
                                            {cell(
                                                view.totals,
                                                column.key,
                                                column.liters,
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
