import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import { SalesChart } from '@/components/sales-chart';
import { Badge } from '@/components/ui/badge';
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
    formatDateTime,
    formatNumber,
    formatSyp,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { exportPdf, index } from '@/routes/statistics';
import type {
    Auth,
    Currency,
    DebtDirection,
    SalesChartData,
    TransactionTotals,
    TransactionType,
    UserSummary,
} from '@/types';

type FuelTypeRow = {
    name: string;
    liters: number;
    income_syp: number;
};

type ByUserRow = {
    user: UserSummary;
    totals: TransactionTotals;
};

type TransactionRow = {
    id: number;
    type: TransactionType;
    description: string;
    tank_name: string | null;
    liters: number | null;
    amount: number;
    currency: Currency;
    occurred_at: string;
    is_governmental: boolean;
    is_pending_debt: boolean;
};

type DeliveryRow = {
    id: number;
    occurred_at: string;
    tank_name: string | null;
    fuel_type_name: string | null;
    liters: number;
    price_per_liter: number | null;
    amount: number;
    currency: Currency;
    paid_by_sadcop: boolean;
};

type DebtCreatedRow = {
    id: number;
    debtor_name: string;
    direction: DebtDirection;
    amount: number;
    currency: Currency;
    date: string;
};

type DebtSettledRow = {
    id: number;
    debtor_name: string;
    direction: DebtDirection | null;
    amount: number;
    currency: Currency | null;
    paid_at: string;
};

type PageProps = {
    auth: Auth;
    totals: TransactionTotals;
    byFuelType: FuelTypeRow[];
    salesChart: SalesChartData;
    transactions: TransactionRow[];
    deliveries: DeliveryRow[];
    debtsCreated: DebtCreatedRow[];
    debtsSettled: DebtSettledRow[];
    from: string;
    to: string;
    byUser?: ByUserRow[];
};

function TotalsGrid({
    totals,
    t,
}: {
    totals: TransactionTotals;
    t: (key: TranslationKey) => string;
}) {
    return (
        <div className="grid auto-rows-min gap-4 md:grid-cols-5">
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.income')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.income_syp)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.expenses')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.expense_syp)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.net')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.net_syp)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>
                        {t('dashboard.liters_sold')}
                    </CardDescription>
                    <CardTitle className="text-2xl">
                        {formatNumber(totals.liters_sold)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>
                        {t('dashboard.liters_delivered')}
                    </CardDescription>
                    <CardTitle className="text-2xl">
                        {formatNumber(totals.liters_delivered)}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>
    );
}

export default function StatisticsIndex() {
    const {
        auth,
        totals,
        byFuelType,
        salesChart,
        transactions,
        deliveries,
        debtsCreated,
        debtsSettled,
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
        const today = new Date().toISOString().slice(0, 10);
        setFromVal(today);
        setToVal(today);
        router.get(
            index.url(),
            { from: today, to: today },
            { preserveState: false },
        );
    }

    const transactionTypeLabels: Record<TransactionType, string> = {
        fuel_sale: t('transactions.type.fuel_sale'),
        fuel_delivery: t('transactions.type.fuel_delivery'),
        other_income: t('transactions.type.other_income'),
        expense: t('transactions.type.expense'),
        purchase: t('transactions.type.purchase'),
        currency_exchange: t('transactions.type.currency_exchange'),
    };

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

                <TotalsGrid totals={totals} t={t} />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('statistics.transactions')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-start">
                                        <th className="px-4 py-2">
                                            {t('common.date')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.type')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('transactions.description')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.liters')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.amount')}
                                        </th>
                                        <th className="px-4 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.map((txn) => (
                                        <tr key={txn.id} className="border-t">
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {formatDateTime(
                                                    txn.occurred_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {transactionTypeLabels[
                                                    txn.type
                                                ] ?? txn.type}
                                            </td>
                                            <td className="px-4 py-2">
                                                {txn.description}
                                                {txn.tank_name &&
                                                    ` — ${txn.tank_name}`}
                                            </td>
                                            <td className="px-4 py-2">
                                                {txn.liters !== null
                                                    ? `${formatNumber(txn.liters)} L`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatCurrencyAmount(
                                                    txn.amount,
                                                    txn.currency,
                                                )}
                                            </td>
                                            <td className="space-x-1 px-4 py-2">
                                                {txn.is_governmental && (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'statistics.governmental',
                                                        )}
                                                    </Badge>
                                                )}
                                                {txn.is_pending_debt && (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'statistics.pending_debt',
                                                        )}
                                                    </Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {transactions.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                {t('common.no_results')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('statistics.deliveries')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-start">
                                        <th className="px-4 py-2">
                                            {t('common.date')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.tank')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.liters')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('sadcop.cost_price_per_liter')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.amount')}
                                        </th>
                                        <th className="px-4 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {deliveries.map((delivery) => (
                                        <tr
                                            key={delivery.id}
                                            className="border-t"
                                        >
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {formatDateTime(
                                                    delivery.occurred_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {delivery.fuel_type_name} —{' '}
                                                {delivery.tank_name}
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatNumber(delivery.liters)}{' '}
                                                L
                                            </td>
                                            <td className="px-4 py-2">
                                                {delivery.price_per_liter !==
                                                null
                                                    ? formatNumber(
                                                          delivery.price_per_liter,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatCurrencyAmount(
                                                    delivery.amount,
                                                    delivery.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {delivery.paid_by_sadcop && (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'statistics.paid_by_sadcop',
                                                        )}
                                                    </Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {deliveries.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                {t('common.no_results')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('statistics.debts_created')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-2">
                                                {t('common.debtor')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('debts.direction')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.amount')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {debtsCreated.map((debt) => (
                                            <tr
                                                key={debt.id}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-2">
                                                    {debt.debtor_name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {t(
                                                        `debts.direction.${debt.direction}`,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatCurrencyAmount(
                                                        debt.amount,
                                                        debt.currency,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {debtsCreated.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={3}
                                                    className="px-4 py-6 text-center text-muted-foreground"
                                                >
                                                    {t('common.no_results')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('statistics.debts_settled')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-2">
                                                {t('common.debtor')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('debts.direction')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.amount')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {debtsSettled.map((payment) => (
                                            <tr
                                                key={payment.id}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-2">
                                                    {payment.debtor_name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {payment.direction
                                                        ? t(
                                                              `debts.direction.${payment.direction}`,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {payment.currency
                                                        ? formatCurrencyAmount(
                                                              payment.amount,
                                                              payment.currency,
                                                          )
                                                        : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                        {debtsSettled.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={3}
                                                    className="px-4 py-6 text-center text-muted-foreground"
                                                >
                                                    {t('common.no_results')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <SalesChart chart={salesChart} />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('statistics.by_fuel_type')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-start">
                                        <th className="px-4 py-2">
                                            {t('common.fuel_type')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('dashboard.liters_sold')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('dashboard.income')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byFuelType.map((row) => (
                                        <tr key={row.name} className="border-t">
                                            <td className="px-4 py-2 font-medium">
                                                {row.name}
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatNumber(row.liters)} L
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatSyp(row.income_syp)}
                                            </td>
                                        </tr>
                                    ))}
                                    {byFuelType.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                {t('common.no_results')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {auth.isAdmin && byUser && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('statistics.by_employee')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-2">
                                                {t('common.employee')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('dashboard.income')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('dashboard.expenses')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('dashboard.net')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('dashboard.liters_sold')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {byUser.map((row) => (
                                            <tr
                                                key={row.user.id}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-2 font-medium">
                                                    {row.user.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatSyp(
                                                        row.totals.income_syp,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatSyp(
                                                        row.totals.expense_syp,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatSyp(
                                                        row.totals.net_syp,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatNumber(
                                                        row.totals.liters_sold,
                                                    )}{' '}
                                                    L
                                                </td>
                                            </tr>
                                        ))}
                                        {byUser.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-4 py-6 text-center text-muted-foreground"
                                                >
                                                    {t('common.no_results')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

StatisticsIndex.layout = {
    breadcrumbs: [{ title: 'Statistics', href: index() }],
};
