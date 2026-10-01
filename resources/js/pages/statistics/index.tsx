import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import { SalesChart } from '@/components/sales-chart';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    formatCurrencyAmount,
    formatNumber,
    formatSyp,
    todayInStation,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { exportPdf, index } from '@/routes/statistics';
import type {
    Auth,
    Currency,
    SalesChartData,
    TransactionTotals,
    UserSummary,
} from '@/types';

type FuelTypeRow = {
    name: string;
    liters: number;
    income_syp: number;
};

type DeliveryRow = {
    name: string;
    liters: number;
    cost_syp: number;
};

type ByUserRow = {
    user: UserSummary;
    totals: TransactionTotals;
};

type DebtPosition = {
    receivable: Partial<Record<Currency, number>>;
    payable: Partial<Record<Currency, number>>;
};

type ShopSales = {
    total_syp: number;
    items: { name: string; quantity: number; revenue_syp: number }[];
};

type PageProps = {
    auth: Auth;
    totals: TransactionTotals;
    byFuelType: FuelTypeRow[];
    salesChart: SalesChartData;
    deliveriesByFuelType: DeliveryRow[];
    debtPosition: DebtPosition;
    shopSales: ShopSales;
    from: string;
    to: string;
    byUser?: ByUserRow[];
};

// Fuel types are colored by their position in the chart's (name-sorted) fuel type list, the
// same order the sales chart assigns its line colors -- so petrol is amber and diesel blue in
// both the chart and the breakdown cards.
const FUEL_TEXT = [
    'text-amber-600 dark:text-amber-400',
    'text-blue-600 dark:text-blue-400',
];

// Store sales use teal so they never read as a fuel type (amber/blue).
const STORE_TEXT = 'text-teal-600 dark:text-teal-400';

function KpiCard({
    label,
    value,
    accent,
}: {
    label: string;
    value: string;
    accent: string;
}) {
    return (
        <Card className={cn('gap-2 border-t-4 py-4', accent)}>
            <CardHeader className="px-4">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl font-bold">{value}</CardTitle>
            </CardHeader>
        </Card>
    );
}

function BreakdownCard({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="divide-y px-4">{children}</CardContent>
        </Card>
    );
}

function BreakdownRow({
    label,
    labelClassName,
    value,
    detail,
}: {
    label: string;
    labelClassName?: string;
    value: ReactNode;
    detail?: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-2">
            <span className={cn('font-medium', labelClassName)}>{label}</span>
            <span className="text-end">
                <span className="block font-bold">{value}</span>
                {detail && (
                    <span className="text-muted-foreground block text-xs">
                        {detail}
                    </span>
                )}
            </span>
        </div>
    );
}

function Empty({ label }: { label: string }) {
    return <p className="text-muted-foreground py-2 text-sm">{label}</p>;
}

/** SYP first, then any other currency with a balance, one line each. */
function currencyLines(balances: Partial<Record<Currency, number>>) {
    const entries = Object.entries(balances) as [Currency, number][];

    if (entries.length === 0) {
        return [formatSyp(0)];
    }

    return entries
        .sort(([a], [b]) => (a === 'SYP' ? -1 : b === 'SYP' ? 1 : 0))
        .map(([currency, amount]) =>
            currency === 'SYP'
                ? formatSyp(amount)
                : formatCurrencyAmount(amount, currency),
        );
}

export default function StatisticsIndex() {
    const {
        auth,
        totals,
        byFuelType,
        salesChart,
        deliveriesByFuelType,
        debtPosition,
        shopSales,
        from,
        to,
        byUser,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [fromVal, setFromVal] = useState(from);
    const [toVal, setToVal] = useState(to);

    function apply() {
        router.get(
            index.url(),
            { from: fromVal, to: toVal },
            { preserveState: false },
        );
    }

    function goToToday() {
        const today = todayInStation();
        setFromVal(today);
        setToVal(today);
        router.get(
            index.url(),
            { from: today, to: today },
            { preserveState: false },
        );
    }

    const fuelText = (name: string) =>
        FUEL_TEXT[salesChart.fuelTypes.indexOf(name)] ?? 'text-foreground';

    const employees = [...(byUser ?? [])].sort(
        (a, b) => b.totals.income_syp - a.totals.income_syp,
    );

    const receivable = currencyLines(debtPosition.receivable);
    const payable = currencyLines(debtPosition.payable);

    return (
        <>
            <Head title={t('statistics.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('statistics.title')}
                    description={t('statistics.description')}
                />

                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-4">
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
                            <Button variant="outline" onClick={goToToday}>
                                {t('statistics.today')}
                            </Button>
                            <GeneratePdfButton
                                href={exportPdf.url({
                                    query: { from: fromVal, to: toVal },
                                })}
                            />
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <KpiCard
                        label={t('dashboard.income')}
                        value={formatSyp(totals.income_syp)}
                        accent="border-t-emerald-500"
                    />
                    <KpiCard
                        label={t('dashboard.expenses')}
                        value={formatSyp(totals.expense_syp)}
                        accent="border-t-rose-500"
                    />
                    <KpiCard
                        label={t('statistics.sadcop_payments')}
                        value={formatSyp(totals.sadcop_syp)}
                        accent="border-t-indigo-500"
                    />
                    <KpiCard
                        label={t('dashboard.liters_sold')}
                        value={`${formatNumber(totals.liters_sold)} L`}
                        accent="border-t-slate-500"
                    />
                    <KpiCard
                        label={t('dashboard.liters_delivered')}
                        value={`${formatNumber(totals.liters_delivered)} L`}
                        accent="border-t-slate-500"
                    />
                </div>

                <SalesChart chart={salesChart} />

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
                    <BreakdownCard title={t('statistics.fuel_sales_by_type')}>
                        {byFuelType.map((row) => (
                            <BreakdownRow
                                key={row.name}
                                label={row.name}
                                labelClassName={fuelText(row.name)}
                                value={`${formatNumber(row.liters)} L`}
                                detail={formatSyp(row.income_syp)}
                            />
                        ))}
                        {byFuelType.length === 0 && (
                            <Empty label={t('common.no_results')} />
                        )}
                    </BreakdownCard>

                    <BreakdownCard title={t('statistics.sadcop_supplies')}>
                        {deliveriesByFuelType.map((row) => (
                            <BreakdownRow
                                key={row.name}
                                label={row.name}
                                labelClassName={fuelText(row.name)}
                                value={`${formatNumber(row.liters)} L`}
                                detail={formatSyp(row.cost_syp)}
                            />
                        ))}
                        {deliveriesByFuelType.length === 0 && (
                            <Empty label={t('common.no_results')} />
                        )}
                    </BreakdownCard>

                    <BreakdownCard title={t('statistics.shop_sales')}>
                        <BreakdownRow
                            label={t('statistics.total')}
                            labelClassName={STORE_TEXT}
                            value={
                                <span className={STORE_TEXT}>
                                    {formatSyp(shopSales.total_syp)}
                                </span>
                            }
                        />
                        {shopSales.items.map((item) => (
                            <BreakdownRow
                                key={item.name}
                                label={item.name}
                                value={formatSyp(item.revenue_syp)}
                                detail={`${formatNumber(item.quantity, 0)} ${t('statistics.units')}`}
                            />
                        ))}
                        {shopSales.items.length === 0 && (
                            <Empty label={t('common.no_results')} />
                        )}
                    </BreakdownCard>

                    <BreakdownCard
                        title={t('statistics.debt_position')}
                        description={t('statistics.debt_position_note')}
                    >
                        <BreakdownRow
                            label={t('statistics.receivable_total')}
                            labelClassName="text-emerald-600 dark:text-emerald-400"
                            value={receivable[0]}
                            detail={receivable.slice(1).join(' · ') || null}
                        />
                        <BreakdownRow
                            label={t('statistics.payable_total')}
                            labelClassName="text-rose-600 dark:text-rose-400"
                            value={payable[0]}
                            detail={payable.slice(1).join(' · ') || null}
                        />
                    </BreakdownCard>

                    {auth.isAdmin && (
                        <BreakdownCard
                            title={t('statistics.employee_performance')}
                        >
                            {employees.map((row) => (
                                <BreakdownRow
                                    key={row.user.id ?? row.user.name}
                                    label={row.user.name}
                                    value={formatSyp(row.totals.income_syp)}
                                    detail={`${formatNumber(row.totals.liters_sold)} L`}
                                />
                            ))}
                            {employees.length === 0 && (
                                <Empty label={t('common.no_results')} />
                            )}
                        </BreakdownCard>
                    )}
                </div>
            </div>
        </>
    );
}

StatisticsIndex.layout = {
    breadcrumbs: [{ title: 'Statistics', href: index() }],
};
