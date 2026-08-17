import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
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
    base_price: string | null;
    sell_price: string | null;
    currency: Currency;
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

function movementDefaults(item: ShopItem): MovementFormState {
    return {
        quantity: '',
        amount: '',
        currency: item.currency,
        date: new Date().toISOString().slice(0, 10),
    };
}

function ItemCard({ item }: { item: ShopItem }) {
    const { t } = useTranslation();
    const [buyOpen, setBuyOpen] = useState(false);
    const [sellOpen, setSellOpen] = useState(false);
    const [purchase, setPurchase] = useState<MovementFormState>(() =>
        movementDefaults(item),
    );
    const [sale, setSale] = useState<MovementFormState>(() =>
        movementDefaults(item),
    );
    const [purchaseErrors, setPurchaseErrors] = useState<
        Record<string, string>
    >({});
    const [saleErrors, setSaleErrors] = useState<Record<string, string>>({});

    function openBuy() {
        setPurchase(movementDefaults(item));
        setPurchaseErrors({});
        setBuyOpen(true);
    }

    function openSell() {
        setSale(movementDefaults(item));
        setSaleErrors({});
        setSellOpen(true);
    }

    function handlePurchaseQuantityChange(quantity: string) {
        const qty = parseFloat(quantity);
        const basePrice = item.base_price ? parseFloat(item.base_price) : null;
        const computed =
            basePrice !== null && Number.isFinite(qty) ? qty * basePrice : null;

        setPurchase((data) => ({
            ...data,
            quantity,
            amount: computed !== null ? computed.toFixed(2) : data.amount,
        }));
    }

    function handleSaleQuantityChange(quantity: string) {
        const qty = parseFloat(quantity);
        const sellPrice = item.sell_price ? parseFloat(item.sell_price) : null;
        const computed =
            sellPrice !== null && Number.isFinite(qty) ? qty * sellPrice : null;

        setSale((data) => ({
            ...data,
            quantity,
            amount: computed !== null ? computed.toFixed(2) : data.amount,
        }));
    }

    function submitPurchase(event: FormEvent) {
        event.preventDefault();
        router.post(
            storePurchase.url(),
            { shop_item_id: item.id, ...purchase },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBuyOpen(false);
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
                    setSellOpen(false);
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
            <CardContent className="space-y-3 text-sm">
                <div className="flex items-center justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('shop.stock')}
                    </span>
                    <span>{formatNumber(item.stock, 0)}</span>
                </div>
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span>
                        {t('shop.base_price')}:{' '}
                        {item.base_price
                            ? `${formatNumber(parseFloat(item.base_price))} ${item.currency}`
                            : '—'}
                    </span>
                    <span>
                        {t('shop.sell_price')}:{' '}
                        {item.sell_price
                            ? `${formatNumber(parseFloat(item.sell_price))} ${item.currency}`
                            : '—'}
                    </span>
                </div>

                <div className="flex gap-2 border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="flex-1"
                        onClick={openBuy}
                    >
                        {t('shop.buy')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="flex-1"
                        onClick={openSell}
                    >
                        {t('shop.sell')}
                    </Button>
                </div>
            </CardContent>

            <Dialog open={buyOpen} onOpenChange={setBuyOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('shop.buy')} — {item.name}
                    </DialogTitle>

                    <form onSubmit={submitPurchase} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor={`purchase_quantity_${item.id}`}>
                                {t('shop.quantity')}
                            </Label>
                            <Input
                                id={`purchase_quantity_${item.id}`}
                                type="number"
                                step="1"
                                min="1"
                                value={purchase.quantity}
                                onChange={(e) =>
                                    handlePurchaseQuantityChange(e.target.value)
                                }
                            />
                            <InputError message={purchaseErrors.quantity} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor={`purchase_amount_${item.id}`}>
                                    {t('common.amount')}
                                </Label>
                                <MoneyInput
                                    id={`purchase_amount_${item.id}`}
                                    value={purchase.amount}
                                    onChange={(value) =>
                                        setPurchase((data) => ({
                                            ...data,
                                            amount: value,
                                        }))
                                    }
                                />
                                <InputError message={purchaseErrors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={`purchase_currency_${item.id}`}>
                                    {t('common.currency')}
                                </Label>
                                <Select
                                    value={purchase.currency}
                                    onValueChange={(value) =>
                                        setPurchase((data) => ({
                                            ...data,
                                            currency: value as Currency,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id={`purchase_currency_${item.id}`}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="SYP">SYP</SelectItem>
                                        <SelectItem value="TRY">TRY</SelectItem>
                                        <SelectItem value="USD">USD</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`purchase_date_${item.id}`}>
                                {t('common.date')}
                            </Label>
                            <Input
                                id={`purchase_date_${item.id}`}
                                type="date"
                                value={purchase.date}
                                onChange={(e) =>
                                    setPurchase((data) => ({
                                        ...data,
                                        date: e.target.value,
                                    }))
                                }
                            />
                            <InputError message={purchaseErrors.date} />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button type="submit">
                                {t('shop.record_purchase')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={sellOpen} onOpenChange={setSellOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('shop.sell')} — {item.name}
                    </DialogTitle>

                    <form onSubmit={submitSale} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor={`sale_quantity_${item.id}`}>
                                {t('shop.quantity')}
                                <span className="ms-2 text-xs font-normal text-muted-foreground">
                                    ({t('common.max')}: {item.stock})
                                </span>
                            </Label>
                            <Input
                                id={`sale_quantity_${item.id}`}
                                type="number"
                                step="1"
                                min="1"
                                max={item.stock}
                                value={sale.quantity}
                                onChange={(e) =>
                                    handleSaleQuantityChange(e.target.value)
                                }
                            />
                            <InputError message={saleErrors.quantity} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor={`sale_amount_${item.id}`}>
                                    {t('common.amount')}
                                </Label>
                                <MoneyInput
                                    id={`sale_amount_${item.id}`}
                                    value={sale.amount}
                                    onChange={(value) =>
                                        setSale((data) => ({
                                            ...data,
                                            amount: value,
                                        }))
                                    }
                                />
                                <InputError message={saleErrors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={`sale_currency_${item.id}`}>
                                    {t('common.currency')}
                                </Label>
                                <Select
                                    value={sale.currency}
                                    onValueChange={(value) =>
                                        setSale((data) => ({
                                            ...data,
                                            currency: value as Currency,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id={`sale_currency_${item.id}`}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="SYP">SYP</SelectItem>
                                        <SelectItem value="TRY">TRY</SelectItem>
                                        <SelectItem value="USD">USD</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`sale_date_${item.id}`}>
                                {t('common.date')}
                            </Label>
                            <Input
                                id={`sale_date_${item.id}`}
                                type="date"
                                value={sale.date}
                                onChange={(e) =>
                                    setSale((data) => ({
                                        ...data,
                                        date: e.target.value,
                                    }))
                                }
                            />
                            <InputError message={saleErrors.date} />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button type="submit">
                                {t('shop.record_sale')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

export default function ShopIndex() {
    const { auth, items, history, date } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [showAddItem, setShowAddItem] = useState(false);
    const newItemForm = useForm({
        name: '',
        base_price: '',
        sell_price: '',
        currency: 'SYP' as Currency,
    });

    function submitNewItem(event: FormEvent) {
        event.preventDefault();
        newItemForm.post(storeItem.url(), {
            preserveScroll: true,
            onSuccess: () => {
                newItemForm.reset();
                setShowAddItem(false);
            },
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
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('shop.title')}
                        description={t('shop.description')}
                    />
                    <Button type="button" onClick={() => setShowAddItem(true)}>
                        {t('shop.add_item')}
                    </Button>
                </div>

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

            <Dialog open={showAddItem} onOpenChange={setShowAddItem}>
                <DialogContent>
                    <DialogTitle>{t('shop.add_item')}</DialogTitle>

                    <form onSubmit={submitNewItem} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new_item_name">
                                {t('shop.new_item')}
                            </Label>
                            <Input
                                id="new_item_name"
                                value={newItemForm.data.name}
                                onChange={(e) =>
                                    newItemForm.setData('name', e.target.value)
                                }
                                placeholder={t('shop.item_name_placeholder')}
                            />
                            <InputError message={newItemForm.errors.name} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="new_item_base_price">
                                    {t('shop.base_price')}
                                </Label>
                                <MoneyInput
                                    id="new_item_base_price"
                                    value={newItemForm.data.base_price}
                                    onChange={(value) =>
                                        newItemForm.setData('base_price', value)
                                    }
                                />
                                <InputError
                                    message={newItemForm.errors.base_price}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new_item_sell_price">
                                    {t('shop.sell_price')}
                                </Label>
                                <MoneyInput
                                    id="new_item_sell_price"
                                    value={newItemForm.data.sell_price}
                                    onChange={(value) =>
                                        newItemForm.setData('sell_price', value)
                                    }
                                />
                                <InputError
                                    message={newItemForm.errors.sell_price}
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="new_item_currency">
                                {t('common.currency')}
                            </Label>
                            <Select
                                value={newItemForm.data.currency}
                                onValueChange={(value) =>
                                    newItemForm.setData(
                                        'currency',
                                        value as Currency,
                                    )
                                }
                            >
                                <SelectTrigger id="new_item_currency">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="SYP">SYP</SelectItem>
                                    <SelectItem value="TRY">TRY</SelectItem>
                                    <SelectItem value="USD">USD</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={newItemForm.errors.currency} />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={newItemForm.processing}
                            >
                                {t('shop.add_item')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

ShopIndex.layout = {
    breadcrumbs: [{ title: 'Shop', href: index() }],
};
