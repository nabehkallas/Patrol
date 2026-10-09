import { Head, router, usePage } from '@inertiajs/react';
import { LockIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import { PageHeader } from '@/components/page-header';
import { SalesChart } from '@/components/sales-chart';
import { SectionToolbar } from '@/components/section-toolbar';
import { AnnualSummary } from '@/components/statistics/annual-summary';
import type { AnnualSummaryData } from '@/components/statistics/annual-summary';
import { AnnualUnlockDialog } from '@/components/statistics/annual-unlock-dialog';
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
    formatPrimary,
    getPrimaryCurrency,
    todayInStation,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { exportPdf, exportXlsx, index } from '@/routes/statistics';
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
        <Card className={cn('border-t-4', accent)}>
            <CardHeader>
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
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="divide-y">{children}</CardContent>
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

/** The primary currency first, then any other currency with a balance, one line each. */
function currencyLines(balances: Partial<Record<Currency, number>>) {
    const entries = Object.entries(balances) as [Currency, number][];

    if (entries.length === 0) {
        return [formatPrimary(0)];
    }

    return entries
        .sort(([a], [b]) =>
            a === getPrimaryCurrency()
                ? -1
                : b === getPrimaryCurrency()
                  ? 1
                  : 0,
        )
        .map(([currency, amount]) => formatCurrencyAmount(amount, currency));
}

type Tab = 'overview' | 'annual';

type ShellProps = {
    tab: Tab;
    annualUnlocked: boolean;
    annual: AnnualSummaryData | null;
};

export default function StatisticsIndex() {
    const { tab, annualUnlocked, annual } = usePage<ShellProps>().props;
    const { t } = useTranslation();

    // A direct visit to the locked annual tab opens the prompt straight away.
    const [unlockOpen, setUnlockOpen] = useState(
        tab === 'annual' && !annualUnlocked,
    );

    function openTab(next: Tab) {
        if (next === tab) {
            return;
        }

        if (next === 'annual' && !annualUnlocked) {
            setUnlockOpen(true);

            return;
        }

        router.get(
            index.url(next === 'annual' ? { query: { tab: 'annual' } } : {}),
        );
    }

    const tabs: { value: Tab; label: string; locked?: boolean }[] = [
        { value: 'overview', label: t('statistics.tab_overview') },
        {
            value: 'annual',
            label: t('statistics.tab_annual'),
            locked: !annualUnlocked,
        },
    ];

    return (
        <>
            <Head title={t('statistics.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('statistics.title')}
                    description={t('statistics.description')}
                />

                <div className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                    {tabs.map(({ value, label, locked }) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => openTab(value)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors',
                                tab === value
                                    ? 'shadow-xs bg-white dark:bg-neutral-700 dark:text-neutral-100'
                                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                            )}
                        >
                            {locked && <LockIcon className="size-3.5" />}
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'annual' ? (
                    annual ? (
                        <AnnualSummary data={annual} />
                    ) : (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                                <LockIcon className="text-muted-foreground size-8" />
                                <p className="text-muted-foreground text-sm">
                                    {t('statistics.annual_locked')}
                                </p>
                                <Button onClick={() => setUnlockOpen(true)}>
                                    {t('statistics.annual_unlock')}
                                </Button>
                            </CardContent>
                        </Card>
                    )
                ) : (
                    <Overview />
                )}
            </div>

            <AnnualUnlockDialog
                open={unlockOpen}
                onOpenChange={setUnlockOpen}
            />
        </>
    );
}

function Overview() {
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

    // Picking a range shows it straight away, like every other report.
    function show(range: { from: string; to: string }) {
        router.get(index.url(), range, { preserveState: false });
    }

    function goToToday() {
        const today = todayInStation();
        show({ from: today, to: today });
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
            <div className="space-y-6">
                <SectionToolbar
                    title={t('statistics.period')}
                    children={
                        <>
                            <DateRangePicker
                                from={from}
                                to={to}
                                onChange={show}
                            />
                            <Button variant="outline" onClick={goToToday}>
                                {t('statistics.today')}
                            </Button>
                        </>
                    }
                    actions={
                        <>
                            <GeneratePdfButton
                                href={exportPdf.url({ query: { from, to } })}
                            />
                            <GenerateXlsxButton
                                href={exportXlsx.url({ query: { from, to } })}
                            />
                        </>
                    }
                />

                <div className="card-grid">
                    <KpiCard
                        label={t('dashboard.income')}
                        value={formatPrimary(totals.income_syp)}
                        accent="border-t-emerald-500"
                    />
                    <KpiCard
                        label={t('dashboard.expenses')}
                        value={formatPrimary(totals.expense_syp)}
                        accent="border-t-rose-500"
                    />
                    <KpiCard
                        label={t('statistics.sadcop_payments')}
                        value={formatPrimary(totals.sadcop_syp)}
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

                {/* Cards wrap and stretch to fill each row, so no row ends in an empty slot. */}
                <div className="panel-grid">
                    <BreakdownCard title={t('statistics.fuel_sales_by_type')}>
                        {byFuelType.map((row) => (
                            <BreakdownRow
                                key={row.name}
                                label={row.name}
                                labelClassName={fuelText(row.name)}
                                value={`${formatNumber(row.liters)} L`}
                                detail={formatPrimary(row.income_syp)}
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
                                detail={formatPrimary(row.cost_syp)}
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
                                    {formatPrimary(shopSales.total_syp)}
                                </span>
                            }
                        />
                        {shopSales.items.map((item) => (
                            <BreakdownRow
                                key={item.name}
                                label={item.name}
                                value={formatPrimary(item.revenue_syp)}
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
                                    value={formatPrimary(row.totals.income_syp)}
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
    breadcrumbs: [{ title: 'nav.statistics', href: index() }],
};
