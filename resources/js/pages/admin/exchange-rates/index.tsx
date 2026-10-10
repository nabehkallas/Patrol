import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CurrencyOptions } from '@/components/currency-options';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { PageHeader } from '@/components/page-header';
import PaginationLinks from '@/components/pagination-links';
import { SectionToolbar } from '@/components/section-toolbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatNumber, getPrimaryCurrency } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index, store } from '@/routes/admin/exchange-rates';
import type { ExchangeRate, Paginated } from '@/types';

type PageProps = {
    rates: Paginated<ExchangeRate>;
};

export default function ExchangeRatesIndex() {
    const { rates } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        currency: (getPrimaryCurrency() === 'USD'
            ? ''
            : getPrimaryCurrency()) as string,
        rate_to_usd: '',
        effective_at: '',
    });

    // The rate in force for the picked currency: its newest entry in the history below.
    const currentRate = rates.data.find(
        (rate) => rate.currency === form.data.currency,
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), { onSuccess: () => form.reset('rate_to_usd') });
    }

    return (
        <>
            <Head title={t('exchange_rates.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('exchange_rates.title')}
                    description={t('exchange_rates.description_generic')}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('exchange_rates.update_rate')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid items-end gap-4 md:grid-cols-[1fr_1fr_auto]"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="currency">
                                    {t('common.currency')}
                                </Label>
                                <Select
                                    value={form.data.currency}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'currency',
                                            value as 'SYP' | 'TRY',
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="currency"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <CurrencyOptions exclude={['USD']} />
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.currency} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="rate_to_usd">
                                    {t('exchange_rates.units_per_usd')}
                                    {currentRate && (
                                        <span className="text-muted-foreground ms-1 text-xs font-normal">
                                            {t('common.current_value', {
                                                value: formatNumber(
                                                    currentRate.rate_to_usd,
                                                    6,
                                                ),
                                            })}
                                        </span>
                                    )}
                                </Label>
                                <MoneyInput
                                    id="rate_to_usd"
                                    value={form.data.rate_to_usd}
                                    onChange={(value) =>
                                        form.setData('rate_to_usd', value)
                                    }
                                />
                                <InputError message={form.errors.rate_to_usd} />
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                {t('exchange_rates.save_rate')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <SectionToolbar title={t('exchange_rates.history')} />

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('exchange_rates.effective_from')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.currency')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('exchange_rates.rate_to_usd')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.set_by')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rates.data.map((rate) => (
                                <tr key={rate.id} className="border-t">
                                    <td className="px-4 py-3">
                                        {formatDateTime(rate.effective_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {rate.currency}
                                    </td>
                                    <td className="px-4 py-3">
                                        {formatNumber(rate.rate_to_usd, 6)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {rate.set_by?.name}
                                    </td>
                                </tr>
                            ))}
                            {rates.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="text-muted-foreground px-4 py-6 text-center"
                                    >
                                        {t('exchange_rates.empty')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={rates.links} />
            </div>
        </>
    );
}

ExchangeRatesIndex.layout = {
    breadcrumbs: [{ title: 'nav.exchange_rates', href: index() }],
};
