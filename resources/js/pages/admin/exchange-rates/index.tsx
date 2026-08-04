import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatNumber } from '@/lib/format';
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
        currency: 'SYP' as 'SYP' | 'TRY',
        rate_to_usd: '',
        effective_at: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), { onSuccess: () => form.reset('rate_to_usd') });
    }

    return (
        <>
            <Head title={t('exchange_rates.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('exchange_rates.title')}
                    description={t('exchange_rates.description')}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('exchange_rates.update_rate')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-3"
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
                                        <SelectItem value="SYP">
                                            {t('currency.syp')}
                                        </SelectItem>
                                        <SelectItem value="TRY">
                                            {t('currency.try')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.currency} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="rate_to_usd">
                                    {t('exchange_rates.units_per_usd')}
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
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="self-end"
                            >
                                {t('exchange_rates.save_rate')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">
                                    {t('exchange_rates.effective_from')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.currency')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('exchange_rates.rate_to_usd')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.set_by')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rates.data.map((rate) => (
                                <tr key={rate.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {formatDateTime(rate.effective_at)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {rate.currency}
                                    </td>
                                    <td className="px-4 py-2">
                                        {formatNumber(rate.rate_to_usd)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {rate.set_by?.name}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={rates.links} />
            </div>
        </>
    );
}

ExchangeRatesIndex.layout = {
    breadcrumbs: [{ title: 'Exchange rates', href: index() }],
};
