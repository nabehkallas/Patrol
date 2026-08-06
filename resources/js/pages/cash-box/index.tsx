import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
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
import { formatCurrencyAmount, formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { exportPdf, index } from '@/routes/cash-box';
import type { CashBox, CashBoxSummary, Currency } from '@/types';

type PageProps = {
    filters: { from: string; to: string };
    cashBox: CashBox;
};

/** Every currency besides SYP that has activity anywhere in this summary. */
function otherCurrencies(totals: CashBoxSummary): Currency[] {
    const found = new Set<Currency>();

    for (const breakdown of [
        totals.income,
        totals.other_expense,
        totals.exchanged,
        totals.net,
        totals.debts,
    ]) {
        for (const currency of Object.keys(breakdown) as Currency[]) {
            if (currency !== 'SYP') {
                found.add(currency);
            }
        }
    }

    return Array.from(found);
}

function SypGrid({
    totals,
    t,
}: {
    totals: CashBoxSummary;
    t: (key: TranslationKey) => string;
}) {
    return (
        <div className="grid auto-rows-min gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.income')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.income.SYP)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>
                        {t('cash_box.sadcop_payments')}
                    </CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.sadcop_expense_syp)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>
                        {t('cash_box.other_expenses')}
                    </CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.other_expense.SYP)}
                    </CardTitle>
                </CardHeader>
            </Card>
            {totals.exchanged.SYP !== undefined && (
                <Card>
                    <CardHeader>
                        <CardDescription>
                            {t('cash_box.exchanged')}
                        </CardDescription>
                        <CardTitle className="text-2xl">
                            {formatSyp(totals.exchanged.SYP)}
                        </CardTitle>
                    </CardHeader>
                </Card>
            )}
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.net')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.net.SYP)}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>
                        {t('dashboard.liters_sold')}
                    </CardDescription>
                    <CardTitle className="text-2xl">
                        {formatNumber(totals.liters_sold)} L
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>{t('dashboard.debts')}</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatSyp(totals.debts.SYP)}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-1">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {t('dashboard.liters_sold_in_debt')}
                        </span>
                        <span className="font-medium">
                            {formatNumber(totals.debts_liters_sold)} L
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

function OtherCurrencyBoxes({
    totals,
    t,
}: {
    totals: CashBoxSummary;
    t: (key: TranslationKey) => string;
}) {
    const currencies = otherCurrencies(totals);

    if (currencies.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {currencies.map((currency) => (
                <Card key={currency}>
                    <CardHeader>
                        <CardTitle>{currency}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                {t('dashboard.income')}
                            </span>
                            <span className="font-medium">
                                {formatCurrencyAmount(
                                    totals.income[currency] ?? 0,
                                    currency,
                                )}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                {t('cash_box.other_expenses')}
                            </span>
                            <span className="font-medium">
                                {formatCurrencyAmount(
                                    totals.other_expense[currency] ?? 0,
                                    currency,
                                )}
                            </span>
                        </div>
                        {totals.exchanged[currency] !== undefined && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    {t('cash_box.exchanged')}
                                </span>
                                <span className="font-medium">
                                    {formatCurrencyAmount(
                                        totals.exchanged[currency] ?? 0,
                                        currency,
                                    )}
                                </span>
                            </div>
                        )}
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                {t('dashboard.net')}
                            </span>
                            <span className="font-medium">
                                {formatCurrencyAmount(
                                    totals.net[currency] ?? 0,
                                    currency,
                                )}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                {t('dashboard.debts')}
                            </span>
                            <span className="font-medium">
                                {formatCurrencyAmount(
                                    totals.debts[currency] ?? 0,
                                    currency,
                                )}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function CashBoxSection({
    totals,
    t,
}: {
    totals: CashBoxSummary;
    t: (key: TranslationKey) => string;
}) {
    return (
        <div className="space-y-4">
            <SypGrid totals={totals} t={t} />
            <OtherCurrencyBoxes totals={totals} t={t} />
        </div>
    );
}

export default function CashBoxIndex() {
    const { filters, cashBox: totals } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [fromVal, setFromVal] = useState(filters.from);
    const [toVal, setToVal] = useState(filters.to);

    function apply() {
        router.get(
            index.url(),
            { from: fromVal, to: toVal },
            { preserveState: false },
        );
    }

    return (
        <>
            <Head title={t('cash_box.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('cash_box.title')}
                    description={t('cash_box.description')}
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

                <div className="space-y-3">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        {t('dashboard.today')}
                    </h2>
                    <CashBoxSection totals={totals.today} t={t} />
                </div>

                <div className="space-y-3">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        {t('cash_box.selected_period')} ({filters.from} —{' '}
                        {filters.to})
                    </h2>
                    <CashBoxSection totals={totals.period} t={t} />
                </div>
            </div>
        </>
    );
}

CashBoxIndex.layout = {
    breadcrumbs: [{ title: 'Cash Box', href: index() }],
};
