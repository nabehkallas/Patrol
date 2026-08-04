import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { exportPdf, index } from '@/routes/statistics';
import type {
    Auth,
    SalesChartData,
    TransactionTotals,
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

type PageProps = {
    auth: Auth;
    totals: TransactionTotals;
    byFuelType: FuelTypeRow[];
    salesChart: SalesChartData;
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
    const { auth, totals, byFuelType, salesChart, from, to, byUser } =
        usePage<PageProps>().props;
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
                            <div className="grid gap-1">
                                <Label htmlFor="from">
                                    {t('statistics.from')}
                                </Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={fromVal}
                                    onChange={(e) => setFromVal(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="to">{t('statistics.to')}</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={toVal}
                                    onChange={(e) => setToVal(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <Button onClick={apply}>
                                {t('statistics.apply')}
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
