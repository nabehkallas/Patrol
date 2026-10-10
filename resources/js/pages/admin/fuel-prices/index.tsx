import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { CurrencyOptions } from '@/components/currency-options';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { PageHeader } from '@/components/page-header';
import PaginationLinks from '@/components/pagination-links';
import { RowActions } from '@/components/row-actions';
import { SectionToolbar } from '@/components/section-toolbar';
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
import {
    formatDateTime,
    formatNumber,
    todayInStation,
    getPrimaryCurrency,
    formatCurrencyAmount,
    trimDecimal,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import {
    destroy,
    index,
    profitMargin,
    store,
    update,
} from '@/routes/admin/fuel-prices';
import type { Currency, FuelPrice, FuelType, Paginated } from '@/types';

type FuelTypeOption = FuelType & { current_price_syp: number | null };

type PageProps = {
    fuelTypes: FuelTypeOption[];
    prices: Paginated<FuelPrice>;
};

function ProfitMarginRow({ fuelType }: { fuelType: FuelTypeOption }) {
    const { t } = useTranslation();

    const form = useForm({
        profit_margin_percent: trimDecimal(fuelType.profit_margin_percent),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(profitMargin.url(fuelType.id));
    }

    const percent = parseFloat(form.data.profit_margin_percent as string);
    const costPrice =
        fuelType.current_price_syp !== null && Number.isFinite(percent)
            ? fuelType.current_price_syp * (1 - percent / 100)
            : null;

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
                        step="0.000001"
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
                    <span className="text-muted-foreground absolute inset-y-0 end-3 flex items-center text-sm">
                        %
                    </span>
                </div>
                <p className="text-muted-foreground text-xs">
                    {t('fuel_prices.cost_price')}:{' '}
                    {costPrice !== null
                        ? `${formatNumber(costPrice, 3)} SYP`
                        : '—'}
                </p>
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

    const [editingId, setEditingId] = useState<number | null>(null);

    const form = useForm({
        fuel_type_id: String(fuelTypes[0]?.id ?? ''),
        price_per_liter: '',
        currency: getPrimaryCurrency() as Currency,
        effective_at: todayInStation(),
    });

    // What the picked fuel type sells for now: its newest price in the history below.
    const currentPrice = prices.data.find(
        (price) =>
            String(price.fuel_type_id) === String(form.data.fuel_type_id),
    );

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editingId !== null) {
            form.patch(update.url(editingId), {
                onSuccess: () => {
                    setEditingId(null);
                    form.reset();
                },
            });
        } else {
            form.post(store.url(), {
                onSuccess: () => form.reset('price_per_liter'),
            });
        }
    }

    function edit(price: FuelPrice) {
        setEditingId(price.id);
        form.setData({
            fuel_type_id: String(price.fuel_type_id),
            price_per_liter: trimDecimal(price.price_per_liter),
            currency: price.currency,
            effective_at: price.effective_at.slice(0, 10),
        });
    }

    function cancelEdit() {
        setEditingId(null);
        form.reset();
    }

    function remove(price: FuelPrice) {
        router.delete(destroy.url(price.id));
    }

    return (
        <>
            <Head title={t('fuel_prices.title')} />

            <div className="space-y-6">
                <PageHeader
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
                            className="grid gap-4 md:grid-cols-5"
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
                                    {currentPrice && (
                                        <span className="text-muted-foreground ms-1 text-xs font-normal">
                                            {t('common.current_value', {
                                                value: formatCurrencyAmount(
                                                    currentPrice.price_per_liter,
                                                    currentPrice.currency,
                                                ),
                                            })}
                                        </span>
                                    )}
                                </Label>
                                <MoneyInput
                                    id="price_per_liter"
                                    placeholder={
                                        currentPrice
                                            ? trimDecimal(
                                                  currentPrice.price_per_liter,
                                              )
                                            : undefined
                                    }
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
                                        <CurrencyOptions />
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.currency} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="effective_at">
                                    {t('common.date')}
                                </Label>
                                <Input
                                    id="effective_at"
                                    type="date"
                                    value={form.data.effective_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'effective_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.effective_at}
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {editingId !== null
                                        ? t('common.save')
                                        : t('fuel_prices.save_price')}
                                </Button>
                                {editingId !== null && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={cancelEdit}
                                    >
                                        {t('common.cancel')}
                                    </Button>
                                )}
                            </div>
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

                <SectionToolbar title={t('fuel_prices.history')} />

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('exchange_rates.effective_from')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.fuel_type')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.price')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.set_by')}
                                </th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {prices.data.map((price) => (
                                <tr key={price.id} className="border-t">
                                    <td className="px-4 py-3">
                                        {formatDateTime(price.effective_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {price.fuel_type?.name}
                                    </td>
                                    <td className="px-4 py-3">
                                        {formatCurrencyAmount(
                                            price.price_per_liter,
                                            price.currency,
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {price.set_by?.name}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <RowActions
                                            edit={() => edit(price)}
                                            remove={() => remove(price)}
                                        />
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
    breadcrumbs: [{ title: 'nav.fuel_prices', href: index() }],
};
