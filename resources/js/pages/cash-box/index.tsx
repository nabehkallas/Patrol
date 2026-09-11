import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Coins,
    TrendingDown,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    formatCurrencyAmount,
    formatDateTime,
    formatNumber,
    formatSyp,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { exportPdf, exportXlsx, index } from '@/routes/cash-box';
import { create as createTransaction } from '@/routes/transactions';
import type {
    CashBoxHistoryEntry,
    CashBoxSummary,
    Currency,
    CurrencyBreakdown,
} from '@/types';

type PageProps = {
    filters: { mode: 'today' | 'custom'; from: string; to: string };
    cashBox: CashBoxSummary;
    openingBalance: CurrencyBreakdown;
    history: CashBoxHistoryEntry[];
};

/** Every currency besides SYP that has activity anywhere in this summary. */
function otherCurrencies(
    totals: CashBoxSummary,
    openingBalance: CurrencyBreakdown,
): Currency[] {
    const found = new Set<Currency>();

    for (const breakdown of [
        totals.income,
        totals.other_expense,
        totals.exchanged,
        totals.net,
        totals.debts,
        openingBalance,
    ]) {
        for (const currency of Object.keys(breakdown) as Currency[]) {
            if (currency !== 'SYP') {
                found.add(currency);
            }
        }
    }

    return Array.from(found);
}

type Accent = 'indigo' | 'emerald' | 'rose' | 'amber';

const ACCENT_CLASSES: Record<
    Accent,
    { icon: string; value: string; bar: string }
> = {
    indigo: {
        icon: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400',
        value: 'text-indigo-600 dark:text-indigo-400',
        bar: 'bg-indigo-500',
    },
    emerald: {
        icon: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
        value: 'text-emerald-600 dark:text-emerald-400',
        bar: 'bg-emerald-500',
    },
    rose: {
        icon: 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
        value: 'text-rose-600 dark:text-rose-400',
        bar: 'bg-rose-500',
    },
    amber: {
        icon: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        value: 'text-amber-600 dark:text-amber-400',
        bar: 'bg-amber-500',
    },
};

function StatCard({
    accent,
    icon,
    label,
    value,
    subtitle,
    breakdown,
    breakdownLabel,
    children,
}: {
    accent: Accent;
    icon: ReactNode;
    label: string;
    value: string;
    subtitle?: string;
    breakdown?: ReactNode;
    breakdownLabel?: string;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const classes = ACCENT_CLASSES[accent];

    return (
        <Card className="relative overflow-hidden py-0">
            <div
                className={`absolute inset-x-0 top-0 h-1 ${classes.bar}`}
                aria-hidden
            />
            <CardContent className="space-y-3 pt-5">
                <div className="flex items-start justify-between gap-3">
                    <div
                        className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${classes.icon}`}
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
                            <PopoverContent align="end" className="w-64">
                                <p className="mb-2 text-sm font-semibold">
                                    {breakdownLabel}
                                </p>
                                <div className="space-y-1.5">{breakdown}</div>
                            </PopoverContent>
                        </Popover>
                    )}
                </div>

                <div>
                    <p className="text-muted-foreground text-sm">{label}</p>
                    <p className={`text-2xl font-bold ${classes.value}`}>
                        {value}
                    </p>
                    {subtitle && (
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            {subtitle}
                        </p>
                    )}
                </div>

                {children}
            </CardContent>
        </Card>
    );
}

function BreakdownRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}

function OtherCurrencyBoxes({
    totals,
    openingBalance,
    t,
}: {
    totals: CashBoxSummary;
    openingBalance: CurrencyBreakdown;
    t: (key: TranslationKey) => string;
}) {
    const currencies = otherCurrencies(totals, openingBalance);

    if (currencies.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {currencies.map((currency) => (
                <Card key={currency}>
                    <CardContent className="space-y-2 text-sm">
                        <p className="mb-1 font-semibold">{currency}</p>
                        <BreakdownRow
                            label={t('cash_box.opening_balance')}
                            value={formatCurrencyAmount(
                                openingBalance[currency] ?? 0,
                                currency,
                            )}
                        />
                        <BreakdownRow
                            label={t('dashboard.income')}
                            value={formatCurrencyAmount(
                                totals.income[currency] ?? 0,
                                currency,
                            )}
                        />
                        <BreakdownRow
                            label={t('cash_box.other_expenses')}
                            value={formatCurrencyAmount(
                                totals.other_expense[currency] ?? 0,
                                currency,
                            )}
                        />
                        {totals.exchanged[currency] !== undefined && (
                            <BreakdownRow
                                label={t('cash_box.exchanged')}
                                value={formatCurrencyAmount(
                                    totals.exchanged[currency] ?? 0,
                                    currency,
                                )}
                            />
                        )}
                        <BreakdownRow
                            label={t('dashboard.net')}
                            value={formatCurrencyAmount(
                                totals.net[currency] ?? 0,
                                currency,
                            )}
                        />
                        <BreakdownRow
                            label={t('cash_box.current_balance')}
                            value={formatCurrencyAmount(
                                (openingBalance[currency] ?? 0) +
                                    (totals.net[currency] ?? 0),
                                currency,
                            )}
                        />
                        <BreakdownRow
                            label={t('dashboard.debts')}
                            value={formatCurrencyAmount(
                                totals.debts[currency] ?? 0,
                                currency,
                            )}
                        />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function CashBoxHistory({
    entries,
    t,
}: {
    entries: CashBoxHistoryEntry[];
    t: (key: TranslationKey) => string;
}) {
    const typeLabels: Record<CashBoxHistoryEntry['type'], string> = {
        income: t('cash_box.history_type.income'),
        expense: t('cash_box.history_type.expense'),
        purchase: t('cash_box.history_type.purchase'),
        sadcop: t('cash_box.history_type.sadcop'),
        exchange: t('cash_box.history_type.exchange'),
    };

    return (
        <div className="space-y-3">
            <div>
                <h2 className="text-sm font-semibold">
                    {t('cash_box.history_title')}
                </h2>
                <p className="text-muted-foreground text-sm">
                    {t('cash_box.history_description')}
                </p>
            </div>

            <div className="max-h-[32rem] overflow-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 z-10">
                        <tr className="bg-muted text-start">
                            <th className="px-4 py-2">{t('common.date')}</th>
                            <th className="px-4 py-2">{t('common.type')}</th>
                            <th className="px-4 py-2">
                                {t('transactions.detail')}
                            </th>
                            <th className="px-4 py-2">{t('common.amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((entry) => {
                            const isNegative =
                                entry.type === 'expense' ||
                                entry.type === 'purchase' ||
                                entry.type === 'sadcop';
                            const isPositive = entry.type === 'income';

                            return (
                                <tr
                                    key={entry.id}
                                    className="hover:bg-muted/50 border-t transition-colors"
                                >
                                    <td className="text-muted-foreground whitespace-nowrap px-4 py-2">
                                        {formatDateTime(entry.date)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {typeLabels[entry.type]}
                                    </td>
                                    <td className="px-4 py-2">
                                        {entry.description}
                                    </td>
                                    <td
                                        className={`px-4 py-2 font-medium ${
                                            isPositive
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : isNegative
                                                  ? 'text-rose-600 dark:text-rose-400'
                                                  : ''
                                        }`}
                                    >
                                        {isNegative
                                            ? '-'
                                            : isPositive
                                              ? '+'
                                              : ''}
                                        {formatCurrencyAmount(
                                            entry.amount,
                                            entry.currency,
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        {entries.length === 0 && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="text-muted-foreground px-4 py-6 text-center"
                                >
                                    {t('common.no_results')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function CashBoxIndex() {
    const {
        filters,
        cashBox: totals,
        openingBalance,
        history,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [mode, setMode] = useState(filters.mode);
    const [fromVal, setFromVal] = useState(filters.from);
    const [toVal, setToVal] = useState(filters.to);

    function showToday() {
        setMode('today');
        router.get(index.url(), { mode: 'today' }, { preserveState: false });
    }

    function showCustomRange() {
        // Only flips the local UI into "custom" mode -- reveals the date picker + Apply
        // button -- without navigating yet, so picking dates never briefly re-fetches
        // "today" data first. Apply below is what actually commits the custom range.
        setMode('custom');
    }

    function apply() {
        router.get(
            index.url(),
            { mode: 'custom', from: fromVal, to: toVal },
            { preserveState: false },
        );
    }

    return (
        <>
            <Head title={t('cash_box.title')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('cash_box.title')}
                        description={t('cash_box.description')}
                    />

                    <div className="flex flex-wrap items-end gap-3">
                        <div className="bg-muted flex items-center gap-1 rounded-lg p-1">
                            <Button
                                type="button"
                                size="sm"
                                variant={mode === 'today' ? 'default' : 'ghost'}
                                onClick={showToday}
                            >
                                {t('cash_box.today')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    mode === 'custom' ? 'default' : 'ghost'
                                }
                                onClick={showCustomRange}
                            >
                                {t('cash_box.custom_range')}
                            </Button>
                        </div>

                        {mode === 'custom' && (
                            <>
                                <DateRangePicker
                                    from={fromVal}
                                    to={toVal}
                                    onChange={(range) => {
                                        setFromVal(range.from);
                                        setToVal(range.to);
                                    }}
                                />
                                <Button onClick={apply}>
                                    {t('statistics.apply')}
                                </Button>
                            </>
                        )}
                        <GeneratePdfButton
                            href={exportPdf.url({
                                query: { from: fromVal, to: toVal },
                            })}
                        />
                        <GenerateXlsxButton
                            href={exportXlsx.url({
                                query: { from: fromVal, to: toVal },
                            })}
                        />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        accent="indigo"
                        icon={<Wallet className="size-5" />}
                        label={t('cash_box.opening_balance')}
                        value={formatSyp(openingBalance.SYP)}
                    />

                    <StatCard
                        accent="emerald"
                        icon={<TrendingUp className="size-5" />}
                        label={t('cash_box.total_income')}
                        value={formatSyp(totals.income.SYP)}
                        breakdownLabel={t('cash_box.income_breakdown')}
                        breakdown={
                            <>
                                <p className="text-muted-foreground text-xs font-semibold">
                                    {t('cash_box.fuel_sales')}
                                </p>
                                {totals.income_by_source_syp.fuel_sales_by_type.map(
                                    (row) => (
                                        <div
                                            key={row.name}
                                            className="space-y-0.5"
                                        >
                                            <BreakdownRow
                                                label={row.name}
                                                value={formatSyp(
                                                    row.revenue_syp,
                                                )}
                                            />
                                            <p className="text-muted-foreground text-end text-xs">
                                                {formatNumber(row.liters)} L ×{' '}
                                                {formatSyp(row.unit_price_syp)}
                                            </p>
                                        </div>
                                    ),
                                )}
                                {totals.income_by_source_syp.fuel_sales_by_type
                                    .length === 0 && (
                                    <BreakdownRow
                                        label={t('cash_box.fuel_sales')}
                                        value={formatSyp(
                                            totals.income_by_source_syp
                                                .fuel_sales,
                                        )}
                                    />
                                )}
                                <BreakdownRow
                                    label={t('cash_box.store_income')}
                                    value={formatSyp(
                                        totals.income_by_source_syp
                                            .store_income,
                                    )}
                                />
                                <BreakdownRow
                                    label={t('cash_box.debt_collections')}
                                    value={formatSyp(
                                        totals.income_by_source_syp
                                            .debt_collections,
                                    )}
                                />
                            </>
                        }
                    />

                    <StatCard
                        accent="rose"
                        icon={<TrendingDown className="size-5" />}
                        label={t('cash_box.total_outflow')}
                        value={formatSyp(
                            totals.sadcop_expense_syp +
                                (totals.other_expense.SYP ?? 0),
                        )}
                        breakdownLabel={t('cash_box.outflow_breakdown')}
                        breakdown={
                            <>
                                <BreakdownRow
                                    label={t('cash_box.sadcop_payments')}
                                    value={formatSyp(totals.sadcop_expense_syp)}
                                />
                                <BreakdownRow
                                    label={t('cash_box.other_expenses')}
                                    value={formatSyp(
                                        totals.other_expense.SYP ?? 0,
                                    )}
                                />
                            </>
                        }
                    />

                    <StatCard
                        accent="amber"
                        icon={<Coins className="size-5" />}
                        label={t('cash_box.current_balance')}
                        value={formatSyp(openingBalance.SYP + totals.net.SYP)}
                    >
                        <div className="space-y-1 border-t pt-2">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    {t('dashboard.debts')}
                                </span>
                                <span className="font-medium">
                                    {formatSyp(totals.debts.SYP)}
                                </span>
                            </div>
                        </div>
                    </StatCard>
                </div>

                <OtherCurrencyBoxes
                    totals={totals}
                    openingBalance={openingBalance}
                    t={t}
                />

                <div className="flex items-center gap-3 rounded-xl border p-3">
                    <Button
                        className="gap-2 bg-emerald-600 text-white hover:bg-emerald-700"
                        onClick={() =>
                            router.get(
                                createTransaction.url({
                                    query: { type: 'other_income' },
                                }),
                            )
                        }
                    >
                        <ArrowUpCircle className="size-4" />
                        {t('cash_box.cash_in')}
                    </Button>
                    <Button
                        className="gap-2 bg-rose-600 text-white hover:bg-rose-700"
                        onClick={() =>
                            router.get(
                                createTransaction.url({
                                    query: { type: 'expense' },
                                }),
                            )
                        }
                    >
                        <ArrowDownCircle className="size-4" />
                        {t('cash_box.cash_out')}
                    </Button>
                </div>

                <CashBoxHistory entries={history} t={t} />
            </div>
        </>
    );
}

CashBoxIndex.layout = {
    breadcrumbs: [{ title: 'Cash Box', href: index() }],
};
