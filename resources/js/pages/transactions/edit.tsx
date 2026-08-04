import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/transactions';
import type {
    Currency,
    Debtor,
    TankOption,
    Transaction,
    TransactionType,
} from '@/types';

type PageProps = {
    transaction: Transaction;
    tanks: TankOption[];
    debtors: Debtor[];
    exchangeRates: Record<Currency, number>;
};

export default function TransactionEdit() {
    const { transaction, tanks, debtors, exchangeRates } =
        usePage<PageProps>().props;
    const { t } = useTranslation();

    const typeLabels: Record<TransactionType, string> = {
        fuel_sale: t('transactions.type.fuel_sale'),
        fuel_delivery: t('transactions.type.fuel_delivery'),
        other_income: t('transactions.type.other_income'),
        expense: t('transactions.type.expense'),
    };

    const form = useForm({
        type: transaction.type,
        tank_id: String(transaction.tank_id ?? tanks[0]?.id ?? ''),
        liters: transaction.liters ?? '',
        price_per_liter: transaction.price_per_liter ?? '',
        description: transaction.description ?? '',
        amount: transaction.amount,
        currency: transaction.currency as Currency,
        exchange_rate_to_usd: transaction.exchange_rate_to_usd ?? '',
        notes: transaction.notes ?? '',
        mark_as_debt: transaction.debt != null,
        debt_debtor_id: String(
            transaction.debt?.debtor_id ?? debtors[0]?.id ?? '',
        ),
    });

    const isDebt = form.data.mark_as_debt;

    function toggleDebt(checked: boolean) {
        form.setData('mark_as_debt', checked);
    }

    const tankBased =
        form.data.type === 'fuel_sale' || form.data.type === 'fuel_delivery';

    const selectedTank = useMemo(
        () => tanks.find((tank) => tank.id === Number(form.data.tank_id)),
        [tanks, form.data.tank_id],
    );

    // The transaction being edited is already counted in the tank's expected stock, so if it's
    // an existing delivery into this same tank, add its own liters back to get the true ceiling.
    const maxLiters = useMemo(() => {
        if (!selectedTank) {
            return undefined;
        }

        const isSameDelivery =
            transaction.type === 'fuel_delivery' &&
            Number(transaction.tank_id) === selectedTank.id;

        return (
            selectedTank.remaining_liters +
            (isSameDelivery ? parseFloat(transaction.liters ?? '0') : 0)
        );
    }, [selectedTank, transaction]);

    function handleTankChange(id: string) {
        const tank = tanks.find((item) => item.id === Number(id));

        form.setData((data) => ({
            ...data,
            tank_id: id,
            price_per_liter:
                tank?.currentPrice?.price_per_liter ?? data.price_per_liter,
        }));
    }

    function handleCurrencyChange(currency: Currency) {
        form.setData((data) => ({
            ...data,
            currency,
            exchange_rate_to_usd:
                currency === 'USD' ? '' : String(exchangeRates[currency] ?? ''),
        }));
    }

    function handleLitersOrPriceChange(liters: string, pricePerLiter: string) {
        const computed = parseFloat(liters) * parseFloat(pricePerLiter);

        form.setData((data) => ({
            ...data,
            liters,
            price_per_liter: pricePerLiter,
            amount:
                Number.isFinite(computed) && computed > 0
                    ? computed.toFixed(2)
                    : data.amount,
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            form.data.type === 'fuel_delivery' &&
            maxLiters !== undefined &&
            parseFloat(String(form.data.liters)) > maxLiters
        ) {
            form.setError(
                'liters',
                `${t('common.exceeds_tank_capacity')} (${formatNumber(maxLiters)} L)`,
            );

            return;
        }

        form.put(update.url(transaction.id));
    }

    return (
        <>
            <Head title={t('transactions.edit')} />

            <div className="max-w-xl space-y-6">
                <Heading variant="small" title={t('transactions.edit')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="type">{t('common.type')}</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={(value) =>
                                form.setData('type', value as TransactionType)
                            }
                        >
                            <SelectTrigger id="type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(typeLabels).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.type} />
                    </div>

                    {tankBased && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="tank_id">
                                    {t('common.tank')}
                                </Label>
                                <Select
                                    value={String(form.data.tank_id)}
                                    onValueChange={handleTankChange}
                                >
                                    <SelectTrigger
                                        id="tank_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {tanks.map((tank) => (
                                            <SelectItem
                                                key={tank.id}
                                                value={String(tank.id)}
                                            >
                                                {tank.fuel_type_name} —{' '}
                                                {tank.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.tank_id} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="liters">
                                        {t('common.liters')}
                                        {form.data.type === 'fuel_delivery' &&
                                            maxLiters !== undefined && (
                                                <span className="ms-2 text-xs font-normal text-muted-foreground">
                                                    ({t('common.max')}:{' '}
                                                    {formatNumber(maxLiters)} L)
                                                </span>
                                            )}
                                    </Label>
                                    <Input
                                        id="liters"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        max={
                                            form.data.type === 'fuel_delivery'
                                                ? maxLiters
                                                : undefined
                                        }
                                        value={form.data.liters}
                                        onChange={(e) =>
                                            handleLitersOrPriceChange(
                                                e.target.value,
                                                String(
                                                    form.data.price_per_liter,
                                                ),
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.liters} />
                                </div>
                                {tankBased && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="price_per_liter">
                                            {t('transactions.price_per_liter')}
                                        </Label>
                                        <MoneyInput
                                            id="price_per_liter"
                                            value={form.data.price_per_liter}
                                            onChange={(value) =>
                                                handleLitersOrPriceChange(
                                                    String(form.data.liters),
                                                    value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                form.errors.price_per_liter
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        </>
                    )}

                    {!tankBased && (
                        <div className="grid gap-2">
                            <Label htmlFor="description">
                                {t('transactions.description')}
                            </Label>
                            <Input
                                id="description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">{t('common.amount')}</Label>
                            <MoneyInput
                                id="amount"
                                value={form.data.amount}
                                onChange={(value) =>
                                    form.setData('amount', value)
                                }
                            />
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="currency">
                                {t('common.currency')}
                            </Label>
                            <Select
                                value={form.data.currency}
                                onValueChange={(value) =>
                                    handleCurrencyChange(value as Currency)
                                }
                            >
                                <SelectTrigger id="currency" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="SYP">
                                        Syrian Pound
                                    </SelectItem>
                                    <SelectItem value="TRY">
                                        Turkish Lira
                                    </SelectItem>
                                    <SelectItem value="USD">
                                        US Dollar
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.currency} />
                        </div>
                    </div>

                    {form.data.currency !== 'USD' && (
                        <div className="grid gap-2">
                            <Label htmlFor="exchange_rate_to_usd">
                                {t('transactions.exchange_rate')}
                            </Label>
                            <MoneyInput
                                id="exchange_rate_to_usd"
                                value={form.data.exchange_rate_to_usd}
                                onChange={(value) =>
                                    form.setData('exchange_rate_to_usd', value)
                                }
                                placeholder={String(
                                    exchangeRates[form.data.currency] ?? '',
                                )}
                            />
                            <InputError
                                message={form.errors.exchange_rate_to_usd}
                            />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="notes">{t('common.notes')}</Label>
                        <Textarea
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_debt"
                            checked={isDebt}
                            onCheckedChange={(checked) =>
                                toggleDebt(checked === true)
                            }
                        />
                        <Label htmlFor="is_debt">
                            {t('transactions.mark_as_debt')}
                        </Label>
                    </div>

                    {isDebt && (
                        <div className="grid gap-2">
                            <Label htmlFor="debt_debtor_id">
                                {t('common.debtor')}
                            </Label>
                            <Select
                                value={form.data.debt_debtor_id}
                                onValueChange={(value) =>
                                    form.setData('debt_debtor_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="debt_debtor_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {debtors.map((debtor) => (
                                        <SelectItem
                                            key={debtor.id}
                                            value={String(debtor.id)}
                                        >
                                            {debtor.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.debt_debtor_id} />
                        </div>
                    )}

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

TransactionEdit.layout = {
    breadcrumbs: [
        { title: 'Transactions', href: index() },
        { title: 'Edit', href: '' },
    ],
};
