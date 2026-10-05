import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { CurrencyOptions } from '@/components/currency-options';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { SectionToolbar } from '@/components/section-toolbar';
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
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import {
    formatDateTime,
    formatNumber,
    todayInStation,
    getPrimaryCurrency,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index, exportPdf, exportXlsx } from '@/routes/shop';
import {
    store as storeItem,
    update as updateItem,
    destroy as destroyItem,
} from '@/routes/shop/items';
import { store as storePurchase } from '@/routes/shop/purchases';
import { store as storeSale } from '@/routes/shop/sales';
import { update as updateTransaction } from '@/routes/shop/transactions';
import { destroy as destroyTransaction } from '@/routes/transactions';
import type { Auth, Currency } from '@/types';

type ShopItem = {
    id: number;
    name: string;
    category: string | null;
    stock: number;
    base_price: string | null;
    sell_price: string | null;
    currency: Currency;
};

type HistoryEntry = {
    id: number;
    type: 'purchase' | 'other_income';
    shop_item_id: number;
    item_name: string;
    quantity: number;
    amount: string;
    currency: Currency;
    occurred_at: string;
    recorded_by: string | null;
    notes: string | null;
};

type ItemTotal = {
    id: number;
    name: string;
    category: string | null;
    quantity: number;
};

type LogFilters = {
    from: string;
    to: string;
    shop_item_id: number | null;
    type: 'purchase' | 'sale' | null;
};

type PageProps = {
    auth: Auth;
    items: ShopItem[];
    history: HistoryEntry[];
    itemTotals: ItemTotal[];
    filters: LogFilters;
    summaryType: 'purchase' | 'sale';
    categories: string[];
};

// Radix Select can't use an empty string as an item value, so "All" gets a sentinel value.
const ALL = 'all';

// Categories the shop commonly uses, offered as suggestions in the item form. Any other
// category can still be typed in freely.
const SUGGESTED_CATEGORIES = [
    'بنزين',
    'محروقات',
    'مازوت',
    'بخاخات',
    'زيوت',
    'إكسسوارات',
];

// An item's category colors, used for its card's top border and its sold-quantity badge
// number. Diesel uses the same blue as diesel everywhere else in the app. Anything not listed
// -- including items with no category yet and any new category -- gets slate silver.
const CATEGORY_COLORS = {
    petrol: {
        text: 'text-amber-600 dark:text-amber-400',
        border: 'border-t-amber-500 dark:border-t-amber-500',
    },
    diesel: {
        text: 'text-blue-600 dark:text-blue-400',
        border: 'border-t-blue-500 dark:border-t-blue-500',
    },
    oils: {
        text: 'text-emerald-600 dark:text-emerald-400',
        border: 'border-t-emerald-500 dark:border-t-emerald-500',
    },
    other: {
        text: 'text-slate-600 dark:text-slate-200',
        border: 'border-t-slate-500 dark:border-t-slate-500',
    },
};

function categoryColors(category: string | null) {
    switch (category?.trim().toLowerCase()) {
        case 'بنزين':
        case 'محروقات':
            return CATEGORY_COLORS.petrol;
        case 'مازوت':
        case 'بخاخات':
            return CATEGORY_COLORS.diesel;
        case 'زيوت':
        case 'إكسسوارات':
            return CATEGORY_COLORS.oils;
        default:
            return CATEGORY_COLORS.other;
    }
}

type MovementFormState = {
    quantity: string;
    amount: string;
    currency: Currency;
    date: string;
};

function movementDefaults(
    currency: Currency,
    entryDate: string,
): MovementFormState {
    return {
        quantity: '',
        amount: '',
        currency,
        date: entryDate,
    };
}

function CurrencySelect({
    id,
    value,
    onChange,
}: {
    id: string;
    value: Currency;
    onChange: (value: Currency) => void;
}) {
    return (
        <Select value={value} onValueChange={(v) => onChange(v as Currency)}>
            <SelectTrigger id={id}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <CurrencyOptions />
            </SelectContent>
        </Select>
    );
}

function ItemCard({ item }: { item: ShopItem }) {
    const { t } = useTranslation();
    const defaultEntryDate = useDefaultEntryDate();
    const [buyOpen, setBuyOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [purchase, setPurchase] = useState<MovementFormState>(() =>
        movementDefaults(item.currency, defaultEntryDate),
    );
    const [purchaseErrors, setPurchaseErrors] = useState<
        Record<string, string>
    >({});

    const [sellQuantity, setSellQuantity] = useState('');
    const [sellAmount, setSellAmount] = useState('');
    const [sellDate, setSellDate] = useState(() => defaultEntryDate);
    const [sellErrors, setSellErrors] = useState<Record<string, string>>({});

    const editForm = useForm({
        name: item.name,
        category: item.category ?? '',
        base_price: item.base_price ?? '',
        sell_price: item.sell_price ?? '',
        currency: item.currency,
        effective_at: defaultEntryDate,
    });

    function handleSellQuantityChange(quantity: string) {
        const qty = parseFloat(quantity);
        const sellPrice = item.sell_price ? parseFloat(item.sell_price) : null;
        const computed =
            sellPrice !== null && Number.isFinite(qty) ? qty * sellPrice : null;

        setSellQuantity(quantity);
        setSellAmount(computed !== null ? computed.toFixed(2) : sellAmount);
    }

    function submitSell(event: FormEvent) {
        event.preventDefault();
        router.post(
            storeSale.url(),
            {
                shop_item_id: item.id,
                quantity: sellQuantity,
                amount: sellAmount,
                currency: item.currency,
                date: sellDate,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSellQuantity('');
                    setSellAmount('');
                    setSellDate(defaultEntryDate);
                    setSellErrors({});
                },
                onError: (errors) =>
                    setSellErrors(errors as Record<string, string>),
            },
        );
    }

    function openBuy() {
        setPurchase(movementDefaults(item.currency, defaultEntryDate));
        setPurchaseErrors({});
        setBuyOpen(true);
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

    function openEdit() {
        editForm.setData({
            name: item.name,
            category: item.category ?? '',
            base_price: item.base_price ?? '',
            sell_price: item.sell_price ?? '',
            currency: item.currency,
            effective_at: defaultEntryDate,
        });
        setEditOpen(true);
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        editForm.patch(updateItem.url(item.id), {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
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

    function removeItem() {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroyItem.url(item.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <Card
            className={cn(
                'gap-2 border-t-4 py-2.5 dark:border-slate-700 dark:bg-slate-800/90',
                categoryColors(item.category).border,
            )}
        >
            <CardHeader className="px-3">
                <CardTitle className="flex items-center justify-between text-base">
                    <span>{item.name}</span>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground hover:text-foreground h-6 rounded-md border px-2 text-xs dark:border-slate-700/60 dark:bg-slate-800/80 dark:text-slate-300 dark:hover:bg-slate-700/60 dark:hover:text-white"
                            onClick={openEdit}
                        >
                            {t('common.edit')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground hover:text-foreground h-6 rounded-md border px-2 text-xs dark:border-slate-700/60 dark:bg-slate-800/80 dark:text-slate-300 dark:hover:bg-slate-700/60 dark:hover:text-white"
                            onClick={removeItem}
                        >
                            {t('common.delete')}
                        </Button>
                    </div>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 px-3 text-sm">
                <div className="flex items-center justify-between">
                    <span>
                        <span className="text-xs font-normal text-slate-500">
                            {t('shop.stock')}:
                        </span>{' '}
                        <span className="text-foreground text-sm font-bold dark:text-white">
                            {formatNumber(item.stock, 0)}
                        </span>
                    </span>
                    <span className="text-muted-foreground text-xs">
                        {t('shop.sell_price')}:{' '}
                        {item.sell_price
                            ? `${formatNumber(parseFloat(item.sell_price))} ${item.currency}`
                            : '—'}
                    </span>
                </div>

                <form onSubmit={submitSell} className="space-y-1.5">
                    <div className="flex gap-2">
                        <Input
                            type="number"
                            step="1"
                            min="1"
                            max={item.stock}
                            placeholder={t('shop.quantity')}
                            value={sellQuantity}
                            onChange={(e) =>
                                handleSellQuantityChange(e.target.value)
                            }
                            className="h-8 flex-1"
                        />
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder={t('common.amount')}
                            value={sellAmount}
                            onChange={(e) => setSellAmount(e.target.value)}
                            className="h-8 flex-1"
                        />
                    </div>
                    <div className="flex gap-2">
                        <Input
                            type="date"
                            value={sellDate}
                            onChange={(e) => setSellDate(e.target.value)}
                            className="h-8 min-w-0 flex-1"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            className="h-8 rounded-lg bg-emerald-600 px-3 font-medium text-white hover:bg-emerald-500"
                        >
                            {t('shop.sell')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className="h-8 rounded-lg bg-rose-600 px-3 font-medium text-white hover:bg-rose-500"
                            onClick={openBuy}
                        >
                            {t('shop.buy')}
                        </Button>
                    </div>
                    <InputError message={sellErrors.quantity} />
                    <InputError message={sellErrors.amount} />
                    <InputError message={sellErrors.date} />
                </form>
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
                                <CurrencySelect
                                    id={`purchase_currency_${item.id}`}
                                    value={purchase.currency}
                                    onChange={(value) =>
                                        setPurchase((data) => ({
                                            ...data,
                                            currency: value,
                                        }))
                                    }
                                />
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

            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('common.edit')} — {item.name}
                    </DialogTitle>

                    <form onSubmit={submitEdit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor={`edit_name_${item.id}`}>
                                {t('shop.new_item')}
                            </Label>
                            <Input
                                id={`edit_name_${item.id}`}
                                value={editForm.data.name}
                                onChange={(e) =>
                                    editForm.setData('name', e.target.value)
                                }
                            />
                            <InputError message={editForm.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit_category_${item.id}`}>
                                {t('shop.category')}
                            </Label>
                            <Input
                                id={`edit_category_${item.id}`}
                                list="shop-categories"
                                value={editForm.data.category}
                                onChange={(e) =>
                                    editForm.setData('category', e.target.value)
                                }
                                placeholder={t('shop.category_placeholder')}
                            />
                            <InputError message={editForm.errors.category} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor={`edit_base_price_${item.id}`}>
                                    {t('shop.base_price')}
                                </Label>
                                <MoneyInput
                                    id={`edit_base_price_${item.id}`}
                                    value={editForm.data.base_price}
                                    onChange={(value) =>
                                        editForm.setData('base_price', value)
                                    }
                                />
                                <InputError
                                    message={editForm.errors.base_price}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={`edit_sell_price_${item.id}`}>
                                    {t('shop.sell_price')}
                                </Label>
                                <MoneyInput
                                    id={`edit_sell_price_${item.id}`}
                                    value={editForm.data.sell_price}
                                    onChange={(value) =>
                                        editForm.setData('sell_price', value)
                                    }
                                />
                                <InputError
                                    message={editForm.errors.sell_price}
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit_currency_${item.id}`}>
                                {t('common.currency')}
                            </Label>
                            <CurrencySelect
                                id={`edit_currency_${item.id}`}
                                value={editForm.data.currency}
                                onChange={(value) =>
                                    editForm.setData('currency', value)
                                }
                            />
                            <InputError message={editForm.errors.currency} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit_effective_at_${item.id}`}>
                                {t('shop.effective_date')}
                            </Label>
                            <Input
                                id={`edit_effective_at_${item.id}`}
                                type="date"
                                max={todayInStation()}
                                value={editForm.data.effective_at}
                                onChange={(e) =>
                                    editForm.setData(
                                        'effective_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={editForm.errors.effective_at}
                            />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={editForm.processing}
                            >
                                {t('common.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

export default function ShopIndex() {
    const {
        auth,
        items,
        history,
        itemTotals,
        filters,
        summaryType,
        categories,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [showAddItem, setShowAddItem] = useState(false);
    const newItemForm = useForm({
        name: '',
        category: '',
        base_price: '',
        sell_price: '',
        currency: getPrimaryCurrency() as Currency,
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

    // Any one filter changing keeps the others (date range, item, type) as they are; "All"
    // (null) is dropped from the URL rather than sent as an empty param.
    function applyFilter(updates: Partial<LogFilters>) {
        const next = { ...filters, ...updates };
        const query = Object.fromEntries(
            Object.entries(next).filter(([, value]) => value !== null),
        );

        router.get(index.url(), query, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    const exportQuery = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null),
    );

    function removeHistoryEntry(entry: HistoryEntry) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroyTransaction.url(entry.id), {
                preserveScroll: true,
            });
        }
    }

    const [editingEntry, setEditingEntry] = useState<HistoryEntry | null>(null);
    const editEntryForm = useForm({
        shop_item_id: 0,
        quantity: '',
        amount: '',
        currency: getPrimaryCurrency() as Currency,
        date: '',
    });

    function openEditEntry(entry: HistoryEntry) {
        editEntryForm.setData({
            shop_item_id: entry.shop_item_id,
            quantity: String(entry.quantity),
            amount: entry.amount,
            currency: entry.currency,
            date: entry.occurred_at.slice(0, 10),
        });
        setEditingEntry(entry);
    }

    function submitEditEntry(event: FormEvent) {
        event.preventDefault();

        if (!editingEntry) {
            return;
        }

        editEntryForm.patch(updateTransaction.url(editingEntry.id), {
            preserveScroll: true,
            onSuccess: () => setEditingEntry(null),
        });
    }

    return (
        <>
            <Head title={t('shop.title')} />
            <datalist id="shop-categories">
                {[...new Set([...SUGGESTED_CATEGORIES, ...categories])].map(
                    (category) => (
                        <option key={category} value={category} />
                    ),
                )}
            </datalist>

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

                <div className="grid grid-cols-[repeat(auto-fill,minmax(max(min(100%,300px),calc((100%_-_3rem)/5)),1fr))] gap-3">
                    {items.map((item) => (
                        <ItemCard key={item.id} item={item} />
                    ))}
                    {items.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            {t('common.no_results')}
                        </p>
                    )}
                </div>

                <div className="space-y-3">
                    <div>
                        <h3 className="font-semibold">
                            {summaryType === 'purchase'
                                ? t('shop.quantity_purchased')
                                : t('shop.quantity_sold')}
                        </h3>
                        <p className="text-muted-foreground text-sm">
                            {summaryType === 'purchase'
                                ? t('shop.quantity_purchased_description')
                                : t('shop.quantity_sold_description')}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-3">
                        {itemTotals.map((row) => (
                            <div
                                key={row.id}
                                className="bg-card flex items-center gap-3 rounded-xl border px-4 py-2.5 dark:border-slate-700/60 dark:bg-slate-800/80"
                            >
                                <span className="text-muted-foreground text-sm font-medium dark:text-slate-400">
                                    <bdi>{row.name}</bdi>:
                                </span>
                                <span
                                    className={cn(
                                        'text-xl font-extrabold',
                                        categoryColors(row.category).text,
                                    )}
                                >
                                    {formatNumber(row.quantity, 0)}
                                </span>
                            </div>
                        ))}
                        {itemTotals.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                {t('common.no_results')}
                            </p>
                        )}
                    </div>
                </div>

                <div className="space-y-3">
                    <SectionToolbar
                        title={t('shop.history')}
                        actions={
                            <>
                                <GeneratePdfButton
                                    href={exportPdf.url({ query: exportQuery })}
                                />
                                <GenerateXlsxButton
                                    href={exportXlsx.url({
                                        query: {
                                            from: filters.from,
                                            to: filters.to,
                                        },
                                    })}
                                />
                            </>
                        }
                        filters={
                            <>
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground text-sm">
                                        {t('shop.item')}
                                    </span>
                                    <Select
                                        value={
                                            filters.shop_item_id
                                                ? String(filters.shop_item_id)
                                                : ALL
                                        }
                                        onValueChange={(value) =>
                                            applyFilter({
                                                shop_item_id:
                                                    value === ALL
                                                        ? null
                                                        : Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-44"
                                            aria-label={t('shop.item')}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                {t('common.all')}
                                            </SelectItem>
                                            {items.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground text-sm">
                                        {t('common.type')}
                                    </span>
                                    <Select
                                        value={filters.type ?? ALL}
                                        onValueChange={(value) =>
                                            applyFilter({
                                                type:
                                                    value === ALL
                                                        ? null
                                                        : (value as
                                                              | 'purchase'
                                                              | 'sale'),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-36"
                                            aria-label={t('common.type')}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                {t('common.all')}
                                            </SelectItem>
                                            <SelectItem value="sale">
                                                {t('shop.type.sale')}
                                            </SelectItem>
                                            <SelectItem value="purchase">
                                                {t('shop.type.purchase')}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </>
                        }
                    >
                        <DateRangePicker
                            from={filters.from}
                            to={filters.to}
                            onChange={applyFilter}
                        />
                    </SectionToolbar>

                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-start">
                                    <th className="px-4 py-3">
                                        {t('pump_counters.time')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.type')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('shop.item')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('shop.quantity')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.amount')}
                                    </th>
                                    <th className="px-4 py-3">
                                        {t('common.recorded_by')}
                                    </th>
                                    {auth.isAdmin && (
                                        <th className="px-4 py-3"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((entry) => (
                                    <tr key={entry.id} className="border-t">
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {formatDateTime(entry.occurred_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {entry.type === 'purchase'
                                                ? t('shop.type.purchase')
                                                : t('shop.type.sale')}
                                        </td>
                                        <td className="px-4 py-3">
                                            {entry.item_name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {formatNumber(entry.quantity, 0)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {formatNumber(entry.amount)}{' '}
                                            {entry.currency}
                                        </td>
                                        <td className="px-4 py-3">
                                            {entry.recorded_by}
                                        </td>
                                        {auth.isAdmin && (
                                            <td className="px-4 py-3 text-end">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEditEntry(entry)
                                                    }
                                                >
                                                    {t('common.edit')}
                                                </Button>
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
                                            className="text-muted-foreground px-4 py-6 text-center"
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
                        <div className="grid gap-2">
                            <Label htmlFor="new_item_category">
                                {t('shop.category')}
                            </Label>
                            <Input
                                id="new_item_category"
                                list="shop-categories"
                                value={newItemForm.data.category}
                                onChange={(e) =>
                                    newItemForm.setData(
                                        'category',
                                        e.target.value,
                                    )
                                }
                                placeholder={t('shop.category_placeholder')}
                            />
                            <InputError message={newItemForm.errors.category} />
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
                            <CurrencySelect
                                id="new_item_currency"
                                value={newItemForm.data.currency}
                                onChange={(value) =>
                                    newItemForm.setData('currency', value)
                                }
                            />
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

            <Dialog
                open={editingEntry !== null}
                onOpenChange={(open) => !open && setEditingEntry(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('common.edit')} — {editingEntry?.item_name}
                    </DialogTitle>

                    <form onSubmit={submitEditEntry} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit_entry_item">
                                {t('shop.item')}
                            </Label>
                            <Select
                                value={String(editEntryForm.data.shop_item_id)}
                                onValueChange={(value) =>
                                    editEntryForm.setData(
                                        'shop_item_id',
                                        Number(value),
                                    )
                                }
                            >
                                <SelectTrigger id="edit_entry_item">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {items.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={editEntryForm.errors.shop_item_id}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit_entry_quantity">
                                    {t('shop.quantity')}
                                </Label>
                                <Input
                                    id="edit_entry_quantity"
                                    type="number"
                                    step="1"
                                    min="1"
                                    value={editEntryForm.data.quantity}
                                    onChange={(e) =>
                                        editEntryForm.setData(
                                            'quantity',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={editEntryForm.errors.quantity}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit_entry_amount">
                                    {t('common.amount')}
                                </Label>
                                <MoneyInput
                                    id="edit_entry_amount"
                                    value={editEntryForm.data.amount}
                                    onChange={(value) =>
                                        editEntryForm.setData('amount', value)
                                    }
                                />
                                <InputError
                                    message={editEntryForm.errors.amount}
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit_entry_currency">
                                    {t('common.currency')}
                                </Label>
                                <CurrencySelect
                                    id="edit_entry_currency"
                                    value={editEntryForm.data.currency}
                                    onChange={(value) =>
                                        editEntryForm.setData('currency', value)
                                    }
                                />
                                <InputError
                                    message={editEntryForm.errors.currency}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit_entry_date">
                                    {t('common.date')}
                                </Label>
                                <Input
                                    id="edit_entry_date"
                                    type="date"
                                    value={editEntryForm.data.date}
                                    onChange={(e) =>
                                        editEntryForm.setData(
                                            'date',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={editEntryForm.errors.date}
                                />
                            </div>
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={editEntryForm.processing}
                            >
                                {t('common.save')}
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
