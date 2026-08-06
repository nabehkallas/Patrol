import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
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
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/debts';
import type {
    Currency,
    Debt,
    DebtDirection,
    Debtor,
    DebtStatus,
    FuelTypeWithPrice,
} from '@/types';

type PageProps = {
    debt: Debt;
    debtors: Debtor[];
    fuelTypes: FuelTypeWithPrice[];
    exchangeRates: Record<Currency, number>;
};

type DebtKind = 'money' | 'liters';

export default function DebtEdit() {
    const { debt, debtors, fuelTypes, exchangeRates } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const [debtKind, setDebtKind] = useState<DebtKind>(
        debt.fuel_type_id ? 'liters' : 'money',
    );

    const form = useForm({
        direction: debt.direction,
        debtor_id: String(debt.debtor_id),
        fuel_type_id: debt.fuel_type_id ? String(debt.fuel_type_id) : '',
        liters: debt.liters ?? '',
        price_per_liter: debt.price_per_liter ?? '',
        amount: debt.amount,
        currency: debt.currency,
        exchange_rate_to_usd: debt.exchange_rate_to_usd ?? '',
        date: debt.date.slice(0, 10),
        details: debt.details ?? '',
        status: debt.status,
    });

    function computeAmount(liters: string, pricePerLiter: string): string {
        const computed = parseFloat(liters) * parseFloat(pricePerLiter);

        return Number.isFinite(computed) && computed > 0
            ? computed.toFixed(2)
            : '';
    }

    function handleDebtKindChange(kind: DebtKind) {
        setDebtKind(kind);

        if (kind === 'money') {
            form.setData((data) => ({
                ...data,
                fuel_type_id: '',
                liters: '',
                price_per_liter: '',
            }));

            return;
        }

        const fuelType =
            fuelTypes.find(
                (item) => item.id === Number(form.data.fuel_type_id),
            ) ?? fuelTypes[0];
        const pricePerLiter =
            form.data.price_per_liter ||
            fuelType?.currentPrice?.price_per_liter ||
            '';

        form.setData((data) => ({
            ...data,
            fuel_type_id: String(fuelType?.id ?? ''),
            price_per_liter: pricePerLiter,
            currency: (fuelType?.currentPrice?.currency ??
                data.currency) as Currency,
            amount: computeAmount(data.liters, pricePerLiter),
        }));
    }

    function handleFuelTypeChange(id: string) {
        const fuelType = fuelTypes.find((item) => item.id === Number(id));
        const pricePerLiter = fuelType?.currentPrice?.price_per_liter ?? '';

        form.setData((data) => ({
            ...data,
            fuel_type_id: id,
            price_per_liter: pricePerLiter,
            currency: (fuelType?.currentPrice?.currency ??
                data.currency) as Currency,
            amount: computeAmount(data.liters, pricePerLiter),
        }));
    }

    function handleLitersChange(liters: string) {
        form.setData((data) => ({
            ...data,
            liters,
            amount: computeAmount(liters, data.price_per_liter),
        }));
    }

    function handlePricePerLiterChange(pricePerLiter: string) {
        form.setData((data) => ({
            ...data,
            price_per_liter: pricePerLiter,
            amount: computeAmount(data.liters, pricePerLiter),
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

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(debt.id));
    }

    return (
        <>
            <Head title={t('debts.edit')} />

            <div className="max-w-xl space-y-6">
                <Heading variant="small" title={t('debts.edit')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="direction">
                            {t('debts.direction')}
                        </Label>
                        <Select
                            value={form.data.direction}
                            onValueChange={(value) =>
                                form.setData(
                                    'direction',
                                    value as DebtDirection,
                                )
                            }
                        >
                            <SelectTrigger id="direction" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="receivable">
                                    {t('debts.direction.receivable')}
                                </SelectItem>
                                <SelectItem value="payable">
                                    {t('debts.direction.payable')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.direction} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="debtor_id">{t('common.debtor')}</Label>
                        <Select
                            value={form.data.debtor_id}
                            onValueChange={(value) =>
                                form.setData('debtor_id', value)
                            }
                        >
                            <SelectTrigger id="debtor_id" className="w-full">
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
                        <InputError message={form.errors.debtor_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">{t('common.date')}</Label>
                        <Input
                            id="date"
                            type="date"
                            value={form.data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="debt_kind">
                            {t('debts.debt_kind')}
                        </Label>
                        <Select
                            value={debtKind}
                            onValueChange={(value) =>
                                handleDebtKindChange(value as DebtKind)
                            }
                        >
                            <SelectTrigger id="debt_kind" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="money">
                                    {t('debts.debt_kind_money')}
                                </SelectItem>
                                <SelectItem value="liters">
                                    {t('debts.debt_kind_liters')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {debtKind === 'liters' && (
                        <div className="grid grid-cols-3 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="fuel_type_id">
                                    {t('common.fuel_type')}
                                </Label>
                                <Select
                                    value={form.data.fuel_type_id}
                                    onValueChange={handleFuelTypeChange}
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
                                <Label htmlFor="liters">
                                    {t('common.liters')}
                                </Label>
                                <Input
                                    id="liters"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.data.liters}
                                    onChange={(e) =>
                                        handleLitersChange(e.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.liters} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="price_per_liter">
                                    {t('transactions.price_per_liter')}
                                </Label>
                                <MoneyInput
                                    id="price_per_liter"
                                    value={form.data.price_per_liter}
                                    onChange={(value) =>
                                        handlePricePerLiterChange(value)
                                    }
                                    required
                                />
                                <InputError
                                    message={form.errors.price_per_liter}
                                />
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">
                                {t('common.amount')}
                                {debtKind === 'liters' && (
                                    <span className="ms-1 text-xs text-muted-foreground">
                                        ({t('debts.computed_from_liters')})
                                    </span>
                                )}
                            </Label>
                            <MoneyInput
                                id="amount"
                                value={form.data.amount}
                                onChange={(value) =>
                                    form.setData('amount', value)
                                }
                                readOnly={debtKind === 'liters'}
                                required
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
                                disabled={debtKind === 'liters'}
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
                            />
                            <InputError
                                message={form.errors.exchange_rate_to_usd}
                            />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="status">{t('common.status')}</Label>
                        <Select
                            value={form.data.status}
                            onValueChange={(value) =>
                                form.setData('status', value as DebtStatus)
                            }
                        >
                            <SelectTrigger id="status" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="outstanding">
                                    {t('debts.status.outstanding')}
                                </SelectItem>
                                <SelectItem value="settled">
                                    {t('debts.status.settled')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.status} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="details">{t('common.details')}</Label>
                        <Textarea
                            id="details"
                            value={form.data.details}
                            onChange={(e) =>
                                form.setData('details', e.target.value)
                            }
                        />
                        <InputError message={form.errors.details} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

DebtEdit.layout = {
    breadcrumbs: [
        { title: 'Debts', href: index() },
        { title: 'Edit', href: '' },
    ],
};
