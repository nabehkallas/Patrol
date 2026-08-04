import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import {
    destroy,
    index,
    profitMargin,
    store,
} from '@/routes/admin/fuel-prices';
import type { Currency, FuelPrice, FuelType, Paginated } from '@/types';

type PageProps = {
    fuelTypes: FuelType[];
    prices: Paginated<FuelPrice>;
};

function ProfitMarginRow({ fuelType }: { fuelType: FuelType }) {
    const { t } = useTranslation();

    const form = useForm({
        profit_margin_percent: fuelType.profit_margin_percent ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(profitMargin.url(fuelType.id));
    }

    return (
        <form onSubmit={submit} className="flex items-end gap-2">
            <div className="grid gap-1">
                <Label htmlFor={`profit_margin_${fuelType.id}`}>
                    {fuelType.name}
                </Label>
                <div className="relative">
                    <Input
                        id={`profit_margin_${fuelType.id}`}
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        className="pe-7"
                        value={form.data.profit_margin_percent}
                        onChange={(e) =>
                            form.setData(
                                'profit_margin_percent',
                                e.target.value,
                            )
                        }
                    />
                    <span className="absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                        %
                    </span>
                </div>
                <InputError message={form.errors.profit_margin_percent} />
            </div>
            <Button type="submit" size="sm" disabled={form.processing}>
                {t('common.save')}
            </Button>
        </form>
    );
}

export default function FuelPricesIndex() {
    const { fuelTypes, prices } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        fuel_type_id: String(fuelTypes[0]?.id ?? ''),
        price_per_liter: '',
        currency: 'SYP' as Currency,
        effective_at: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            onSuccess: () => form.reset('price_per_liter'),
        });
    }

    function remove(price: FuelPrice) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(price.id));
        }
    }

    return (
        <>
            <Head title={t('fuel_prices.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('fuel_prices.title')}
                    description={t('fuel_prices.description')}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('fuel_prices.update_price')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-4"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="fuel_type_id">
                                    {t('common.fuel_type')}
                                </Label>
                                <Select
                                    value={String(form.data.fuel_type_id)}
                                    onValueChange={(value) =>
                                        form.setData('fuel_type_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="fuel_type_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {fuelTypes.map((fuelType) => (
                                            <SelectItem
                                                key={fuelType.id}
                                                value={String(fuelType.id)}
                                            >
                                                {fuelType.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={form.errors.fuel_type_id}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="price_per_liter">
                                    {t('transactions.price_per_liter')}
                                </Label>
                                <MoneyInput
                                    id="price_per_liter"
                                    value={form.data.price_per_liter}
                                    onChange={(value) =>
                                        form.setData('price_per_liter', value)
                                    }
                                />
                                <InputError
                                    message={form.errors.price_per_liter}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="currency">
                                    {t('common.currency')}
                                </Label>
                                <Select
                                    value={form.data.currency}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'currency',
                                            value as Currency,
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
                                        <SelectItem value="USD">
                                            {t('currency.usd')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.currency} />
                            </div>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="self-end"
                            >
                                {t('fuel_prices.save_price')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('fuel_prices.profit_margin')}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-6">
                        {fuelTypes.map((fuelType) => (
                            <ProfitMarginRow
                                key={fuelType.id}
                                fuelType={fuelType}
                            />
                        ))}
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
                                    {t('common.fuel_type')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.price')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.set_by')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {prices.data.map((price) => (
                                <tr key={price.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {formatDateTime(price.effective_at)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {price.fuel_type?.name}
                                    </td>
                                    <td className="px-4 py-2">
                                        {formatNumber(price.price_per_liter)}{' '}
                                        {price.currency}
                                    </td>
                                    <td className="px-4 py-2">
                                        {price.set_by?.name}
                                    </td>
                                    <td className="px-4 py-2 text-end">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(price)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={prices.links} />
            </div>
        </>
    );
}

FuelPricesIndex.layout = {
    breadcrumbs: [{ title: 'Fuel prices', href: index() }],
};
