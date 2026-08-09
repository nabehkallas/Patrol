import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
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
import { index, exportPdf } from '@/routes/shop';
import {
    store as storeItem,
    destroy as destroyItem,
} from '@/routes/shop/items';
import { store as storePurchase } from '@/routes/shop/purchases';
import { store as storeSale } from '@/routes/shop/sales';
import { destroy as destroyTransaction } from '@/routes/transactions';
import type { Auth, Currency } from '@/types';

type ShopItem = {
    id: number;
    name: string;
    stock: number;
};

type HistoryEntry = {
    id: number;
    type: 'expense' | 'other_income';
    item_name: string;
    quantity: number;
    amount: string;
    currency: Currency;
    occurred_at: string;
    recorded_by: string | null;
    notes: string | null;
};

type PageProps = {
    auth: Auth;
    items: ShopItem[];
    history: HistoryEntry[];
    date: string;
};

type MovementFormState = {
    quantity: string;
    amount: string;
    currency: Currency;
    date: string;
};

function movementDefaults(): MovementFormState {
    return {
        quantity: '',
        amount: '',
        currency: 'SYP',
        date: new Date().toISOString().slice(0, 10),
    };
}

function ItemCard({ item }: { item: ShopItem }) {
    const { t } = useTranslation();
    const [purchase, setPurchase] =
        useState<MovementFormState>(movementDefaults());
    const [sale, setSale] = useState<MovementFormState>(movementDefaults());
    const [purchaseErrors, setPurchaseErrors] = useState<
        Record<string, string>
    >({});
    const [saleErrors, setSaleErrors] = useState<Record<string, string>>({});

    function submitPurchase(event: FormEvent) {
        event.preventDefault();
        router.post(
            storePurchase.url(),
            { shop_item_id: item.id, ...purchase },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPurchase(movementDefaults());
                    setPurchaseErrors({});
                },
                onError: (errors) =>
                    setPurchaseErrors(errors as Record<string, string>),
            },
        );
    }

    function submitSale(event: FormEvent) {
        event.preventDefault();
        router.post(
            storeSale.url(),
            { shop_item_id: item.id, ...sale },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSale(movementDefaults());
                    setSaleErrors({});
                },
                onError: (errors) =>
                    setSaleErrors(errors as Record<string, string>),
            },
        );
    }

    function removeItem() {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroyItem.url(item.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    <span>{item.name}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={removeItem}
                    >
                        {t('common.delete')}
                    </Button>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                <div className="flex items-center justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('shop.stock')}
                    </span>
                    <span>{formatNumber(item.stock, 0)}</span>
                </div>

                <form
                    onSubmit={submitPurchase}
                    className="space-y-2 border-t pt-3"
                >
                    <p className="text-xs font-medium text-muted-foreground">
                        {t('shop.restock')}
                    </p>
                    <div className="grid grid-cols-3 gap-2">
                        <Input
                            type="number"
                            step="1"
                            min="1"
                            placeholder={t('shop.quantity')}
                            value={purchase.quantity}
                            onChange={(e) =>
                                setPurchase((data) => ({
                                    ...data,
                                    quantity: e.target.value,
                                }))
                            }
                        />
                        <MoneyInput
                            placeholder={t('common.amount')}
                            value={purchase.amount}
                            onChange={(value) =>
                                setPurchase((data) => ({
                                    ...data,
                                    amount: value,
                                }))
                            }
                        />
                        <Select
                            value={purchase.currency}
                            onValueChange={(value) =>
                                setPurchase((data) => ({
                                    ...data,
                                    currency: value as Currency,
                                }))
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="SYP">SYP</SelectItem>
                                <SelectItem value="TRY">TRY</SelectItem>
                                <SelectItem value="USD">USD</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <InputError message={purchaseErrors.quantity} />
                    <InputError message={purchaseErrors.amount} />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        className="w-full"
                    >
                        {t('shop.record_purchase')}
                    </Button>
                </form>

                <form onSubmit={submitSale} className="space-y-2 border-t pt-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        {t('shop.sell')}
                    </p>
                    <div className="grid grid-cols-3 gap-2">
                        <Input
                            type="number"
                            step="1"
                            min="1"
                            placeholder={t('shop.quantity')}
                            value={sale.quantity}
                            onChange={(e) =>
                                setSale((data) => ({
                                    ...data,
                                    quantity: e.target.value,
                                }))
                            }
                        />
                        <MoneyInput
                            placeholder={t('common.amount')}
                            value={sale.amount}
                            onChange={(value) =>
                                setSale((data) => ({ ...data, amount: value }))
                            }
                        />
                        <Select
                            value={sale.currency}
                            onValueChange={(value) =>
                                setSale((data) => ({
                                    ...data,
                                    currency: value as Currency,
                                }))
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="SYP">SYP</SelectItem>
                                <SelectItem value="TRY">TRY</SelectItem>
                                <SelectItem value="USD">USD</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <InputError message={saleErrors.quantity} />
                    <InputError message={saleErrors.amount} />
                    <Button type="submit" size="sm" className="w-full">
                        {t('shop.record_sale')}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

export default function ShopIndex() {
    const { auth, items, history, date } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const newItemForm = useForm({ name: '' });

    function submitNewItem(event: FormEvent) {
        event.preventDefault();
        newItemForm.post(storeItem.url(), {
            preserveScroll: true,
            onSuccess: () => newItemForm.reset(),
        });
    }

    function handleDateChange(newDate: string) {
        router.get(
            index.url(),
            { date: newDate },
            { preserveScroll: true, preserveState: true },
        );
    }

    function removeHistoryEntry(entry: HistoryEntry) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroyTransaction.url(entry.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title={t('shop.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('shop.title')}
                    description={t('shop.description')}
                />

                <Card>
                    <CardContent className="pt-6">
                        <form
                            onSubmit={submitNewItem}
                            className="flex flex-wrap items-end gap-4"
                        >
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="new_item_name">
                                    {t('shop.new_item')}
                                </Label>
                                <Input
                                    id="new_item_name"
                                    value={newItemForm.data.name}
                                    onChange={(e) =>
                                        newItemForm.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={t(
                                        'shop.item_name_placeholder',
                                    )}
                                />
                                <InputError message={newItemForm.errors.name} />
                            </div>
                            <Button
                                type="submit"
                                disabled={newItemForm.processing}
                            >
                                {t('shop.add_item')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-3">
                    {items.map((item) => (
                        <ItemCard key={item.id} item={item} />
                    ))}
                    {items.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('common.no_results')}
                        </p>
                    )}
                </div>

                <div className="space-y-3">
                    <div className="flex items-center gap-4">
                        <h3 className="font-semibold">{t('shop.history')}</h3>
                        <Input
                            type="date"
                            value={date}
                            onChange={(e) => handleDateChange(e.target.value)}
                            className="w-auto"
                        />
                        <GeneratePdfButton
                            href={exportPdf.url({ query: { date } })}
                        />
                    </div>

                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-start">
                                    <th className="px-4 py-2">
                                        {t('pump_counters.time')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.type')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('shop.item')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('shop.quantity')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.amount')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.recorded_by')}
                                    </th>
                                    {auth.isAdmin && (
                                        <th className="px-4 py-2"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((entry) => (
                                    <tr key={entry.id} className="border-t">
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            {formatDateTime(entry.occurred_at)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {entry.type === 'expense'
                                                ? t('shop.type.purchase')
                                                : t('shop.type.sale')}
                                        </td>
                                        <td className="px-4 py-2">
                                            {entry.item_name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatNumber(entry.quantity, 0)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatNumber(entry.amount)}{' '}
                                            {entry.currency}
                                        </td>
                                        <td className="px-4 py-2">
                                            {entry.recorded_by}
                                        </td>
                                        {auth.isAdmin && (
                                            <td className="px-4 py-2 text-end">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeHistoryEntry(
                                                            entry,
                                                        )
                                                    }
                                                >
                                                    {t('common.delete')}
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                                {history.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={auth.isAdmin ? 7 : 6}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            {t('common.no_results')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

ShopIndex.layout = {
    breadcrumbs: [{ title: 'Shop', href: index() }],
};
