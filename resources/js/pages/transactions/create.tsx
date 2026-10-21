import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import {
    CashSourceFields,
    emptyCashSource,
    useCashSource,
} from '@/components/cash-source-fields';
import {
    CurrencyOptions,
    useCurrencyName,
} from '@/components/currency-options';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { PageHeader } from '@/components/page-header';
import { TankSelectItems } from '@/components/tank-select-items';
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
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import {
    CONFIRM_FIELD,
    useNegativeCashConfirm,
} from '@/hooks/use-negative-cash-confirm';
import type { CashSourceInput } from '@/lib/cash-source';
import { cashSourcePayload } from '@/lib/cash-source';
import { defaultRate, giveFor, ratePair, receiveFor } from '@/lib/exchange';
import { formatNumber, getPrimaryCurrency } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { priceAmount } from '@/lib/price-amount';
import { selectableTanks } from '@/lib/tanks';
import { index, store } from '@/routes/transactions';
import type {
    Currency,
    DebtDirection,
    Debtor,
    OtherIncomeCategory,
    TankOption,
    TransactionType,
} from '@/types';

const CURRENCIES: Currency[] = ['SYP', 'TRY', 'USD'];

type PageProps = {
    tanks: TankOption[];
    debtors: Debtor[];
    exchangeRates: Record<Currency, number>;
};

export default function TransactionCreate() {
    const { tanks, debtors, exchangeRates } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const currencyName = useCurrencyName();
    const defaultEntryDate = useDefaultEntryDate();

    // Fuel sales are recorded by pump counters, so they aren't a type to pick here.
    const typeLabels: Record<Exclude<TransactionType, 'fuel_sale'>, string> = {
        fuel_delivery: t('transactions.type.fuel_delivery'),
        other_income: t('transactions.type.other_income'),
        expense: t('transactions.type.expense'),
        purchase: t('transactions.type.purchase'),
        currency_exchange: t('transactions.type.currency_exchange'),
    };

    // Quick-action links (e.g. Cash Box's "Cash In"/"Cash Out" buttons) can jump straight to a
    // given type via ?type=..., instead of always landing on the default and making the user
    // switch it themselves.
    const requestedType = new URLSearchParams(window.location.search).get(
        'type',
    );
    const validTypes: TransactionType[] = [
        'fuel_delivery',
        'other_income',
        'expense',
        'purchase',
        'currency_exchange',
    ];
    const initialType = validTypes.includes(requestedType as TransactionType)
        ? (requestedType as TransactionType)
        : 'expense';

    const initialTank = selectableTanks(tanks)[0];

    const form = useForm({
        type: initialType,
        tank_id: String(initialTank?.id ?? ''),
        liters: '',
        price_per_liter: initialTank?.currentPrice?.price_per_liter ?? '',
        description: '',
        amount: '',
        currency: (initialTank?.currentPrice?.currency ??
            getPrimaryCurrency()) as Currency,
        to_currency: 'USD' as Currency,
        to_amount: '',
        exchange_rate_to_usd: '',
        occurred_at: defaultEntryDate,
        notes: '',
        other_income_category: 'cash_box' as OtherIncomeCategory,
        mark_as_debt: false,
        debt_debtor_id: String(debtors[0]?.id ?? ''),
        debt_direction: 'receivable' as DebtDirection,
    });

    // An expense or purchase paid now takes money out of a box: in another currency, from that
    // currency's box or the primary one at a rate (see CashSourceFields). A debt pays nothing yet.
    const [cash, setCash] = useState<CashSourceInput>(emptyCashSource);
    const paysOut =
        (form.data.type === 'expense' || form.data.type === 'purchase') &&
        !form.data.mark_as_debt;
    const giveBack: Record<string, number> = {};
    const cashSourceNow = useCashSource(
        form.data.amount,
        form.data.currency,
        cash,
        giveBack,
    );
    const negativeCash = useNegativeCashConfirm();

    // Sent with the form: where the money comes from, and -- once asked -- that the primary
    // box may go negative.
    function sendWithCash(send: () => void, confirmed: boolean) {
        form.transform((data) => ({
            ...data,
            ...(paysOut ? cashSourcePayload(cashSourceNow) : {}),
            [CONFIRM_FIELD]: confirmed,
        }));
        send();
    }

    const isDebt = form.data.mark_as_debt;
    const isExchange = form.data.type === 'currency_exchange';

    function toggleDebt(checked: boolean) {
        form.setData('mark_as_debt', checked);
    }

    const tankBased = form.data.type === 'fuel_delivery';
    const showDescription = !tankBased && !isExchange;

    const selectedTank = useMemo(
        () => tanks.find((tank) => tank.id === Number(form.data.tank_id)),
        [tanks, form.data.tank_id],
    );

    const availableTanks = useMemo(
        () => selectableTanks(tanks, form.data.tank_id),
        [tanks, form.data.tank_id],
    );

    // A delivery's price per liter starts as the tank's fuel price, in that price's currency
    // only: another currency clears it (and the amount) to be typed, never converted, and
    // switching back fills it again -- even over a typed price, which was for the old currency.
    const tankPrice = selectedTank?.currentPrice ?? null;

    function handleTankChange(id: string) {
        const tank = tanks.find((item) => item.id === Number(id));

        form.setData((data) => ({
            ...data,
            tank_id: id,
            price_per_liter:
                tank?.currentPrice?.price_per_liter ?? data.price_per_liter,
            currency: (tank?.currentPrice?.currency ??
                data.currency) as Currency,
        }));
    }

    // Only a currency exchange uses a rate: the one in its rate field, which starts at today's
    // saved rate and can be changed. What you receive is worked out from it, either way round,
    // and that rate is what's stored on the exchange.
    function exchangeState(
        data: typeof form.data,
        from: Currency,
        to: Currency,
    ) {
        const rate = defaultRate(from, to, exchangeRates);
        const received =
            rate !== null
                ? receiveFor(parseFloat(data.amount), from, to, rate)
                : null;

        return {
            exchange_rate_to_usd:
                rate !== null ? String(Number(rate.toFixed(6))) : '',
            to_amount: received !== null ? received.toFixed(2) : data.to_amount,
        };
    }

    function handleCurrencyChange(currency: Currency) {
        // A new currency: the delivery price is taken from the tank again (or cleared), and
        // where the money comes from is chosen again.
        setCash(emptyCashSource);
        form.setData((data) => {
            const nextToCurrency =
                data.to_currency === currency
                    ? (CURRENCIES.find((option) => option !== currency) ??
                      data.to_currency)
                    : data.to_currency;

            return {
                ...data,
                currency,
                to_currency: nextToCurrency,
                ...(data.type === 'currency_exchange'
                    ? exchangeState(data, currency, nextToCurrency)
                    : {}),
                ...(data.type === 'fuel_delivery' && tankPrice
                    ? {
                          price_per_liter:
                              tankPrice.currency === currency
                                  ? tankPrice.price_per_liter
                                  : '',
                          amount:
                              priceAmount(
                                  data.liters,
                                  tankPrice.price_per_liter,
                                  tankPrice.currency,
                                  currency,
                              ) ?? '',
                      }
                    : {}),
            };
        });
    }

    function handleToCurrencyChange(currency: Currency) {
        form.setData((data) => {
            const nextCurrency =
                data.currency === currency
                    ? (CURRENCIES.find((option) => option !== currency) ??
                      data.currency)
                    : data.currency;

            return {
                ...data,
                to_currency: currency,
                currency: nextCurrency,
                ...exchangeState(data, nextCurrency, currency),
            };
        });
    }

    function handleGiveAmountChange(value: string) {
        form.setData((data) => {
            const received = receiveFor(
                parseFloat(value),
                data.currency,
                data.to_currency,
                parseFloat(data.exchange_rate_to_usd),
            );

            return {
                ...data,
                amount: value,
                to_amount:
                    received !== null ? received.toFixed(2) : data.to_amount,
            };
        });
    }

    function handleReceiveAmountChange(value: string) {
        form.setData((data) => {
            const given = giveFor(
                parseFloat(value),
                data.currency,
                data.to_currency,
                parseFloat(data.exchange_rate_to_usd),
            );

            return {
                ...data,
                to_amount: value,
                amount: given !== null ? given.toFixed(2) : data.amount,
            };
        });
    }

    function handleRateChange(value: string) {
        form.setData((data) => {
            const received = receiveFor(
                parseFloat(data.amount),
                data.currency,
                data.to_currency,
                parseFloat(value),
            );

            return {
                ...data,
                exchange_rate_to_usd: value,
                to_amount:
                    received !== null ? received.toFixed(2) : data.to_amount,
            };
        });
    }

    // An exchange with no rate yet (just switched to one) starts at today's saved rate.
    useEffect(() => {
        if (isExchange && form.data.exchange_rate_to_usd === '') {
            form.setData((data) => ({
                ...data,
                ...exchangeState(data, data.currency, data.to_currency),
            }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isExchange]);

    const pair = ratePair(form.data.currency, form.data.to_currency);
    const todayRate = defaultRate(
        form.data.currency,
        form.data.to_currency,
        exchangeRates,
    );

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

    function submit(event?: FormEvent, confirmed = false) {
        event?.preventDefault();

        if (
            form.data.type === 'fuel_delivery' &&
            selectedTank &&
            parseFloat(form.data.liters) > selectedTank.remaining_liters
        ) {
            form.setError(
                'liters',
                `${t('common.exceeds_tank_capacity')} (${formatNumber(selectedTank.remaining_liters)} L)`,
            );

            return;
        }

        sendWithCash(
            () =>
                form.post(store.url(), {
                    onError: (errors) =>
                        negativeCash.handle(errors, () =>
                            submit(undefined, true),
                        ),
                }),
            confirmed,
        );
    }

    return (
        <>
            <Head title={t('transactions.new')} />

            <div className="max-w-xl space-y-6">
                <PageHeader title={t('transactions.new')} />

                {negativeCash.dialog}
                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="type">{t('common.type')}</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={(value) =>
                                form.setData('type', value as TransactionType)
                            }
                            name="type"
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

                    <div className="grid gap-2">
                        <Label htmlFor="occurred_at">{t('common.date')}</Label>
                        <Input
                            id="occurred_at"
                            type="date"
                            value={form.data.occurred_at}
                            onChange={(e) =>
                                form.setData('occurred_at', e.target.value)
                            }
                        />
                        <InputError message={form.errors.occurred_at} />
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
                                    name="tank_id"
                                >
                                    <SelectTrigger
                                        id="tank_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <TankSelectItems
                                            tanks={availableTanks}
                                        />
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.tank_id} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="liters">
                                        {t('common.liters')}
                                        {form.data.type === 'fuel_delivery' &&
                                            selectedTank && (
                                                <span className="text-muted-foreground ms-2 text-xs font-normal">
                                                    ({t('common.max')}:{' '}
                                                    {formatNumber(
                                                        selectedTank.remaining_liters,
                                                    )}{' '}
                                                    L)
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
                                                ? selectedTank?.remaining_liters
                                                : undefined
                                        }
                                        value={form.data.liters}
                                        onChange={(e) =>
                                            handleLitersOrPriceChange(
                                                e.target.value,
                                                form.data.price_per_liter,
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
                                                    form.data.liters,
                                                    value,
                                                )
                                            }
                                        />
                                        {tankPrice &&
                                            tankPrice.currency !==
                                                form.data.currency && (
                                                <p
                                                    className="text-muted-foreground text-xs"
                                                    data-test="price-currency-hint"
                                                >
                                                    {t(
                                                        'transactions.price_currency_hint',
                                                        {
                                                            currency:
                                                                currencyName(
                                                                    tankPrice.currency,
                                                                ),
                                                        },
                                                    )}
                                                </p>
                                            )}
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

                    {showDescription && (
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
                                placeholder={
                                    form.data.type === 'expense' ||
                                    form.data.type === 'purchase'
                                        ? 'e.g. Maintenance, salaries'
                                        : 'e.g. Car wash, shop sales'
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>
                    )}

                    {form.data.type === 'other_income' && (
                        <div className="grid gap-2">
                            <Label htmlFor="other_income_category">
                                {t('transactions.other_income_category')}
                            </Label>
                            <Select
                                value={form.data.other_income_category}
                                onValueChange={(value) =>
                                    form.setData(
                                        'other_income_category',
                                        value as OtherIncomeCategory,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="other_income_category"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cash_box">
                                        {t(
                                            'transactions.other_income_category.cash_box',
                                        )}
                                    </SelectItem>
                                    <SelectItem value="government">
                                        {t(
                                            'transactions.other_income_category.government',
                                        )}
                                    </SelectItem>
                                    <SelectItem value="other">
                                        {t(
                                            'transactions.other_income_category.other',
                                        )}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.other_income_category}
                            />
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">
                                {isExchange
                                    ? t('transactions.you_give')
                                    : t('common.amount')}
                            </Label>
                            <MoneyInput
                                id="amount"
                                value={form.data.amount}
                                onChange={(value) =>
                                    isExchange
                                        ? handleGiveAmountChange(value)
                                        : form.setData('amount', value)
                                }
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
                                name="currency"
                            >
                                <SelectTrigger id="currency" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <CurrencyOptions />
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.currency} />
                        </div>
                    </div>

                    {paysOut && (
                        <CashSourceFields
                            amount={form.data.amount}
                            currency={form.data.currency}
                            value={cash}
                            onChange={setCash}
                            giveBack={giveBack}
                            errors={form.errors}
                        />
                    )}

                    {isExchange && (
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="to_amount">
                                    {t('transactions.you_receive')}
                                </Label>
                                <MoneyInput
                                    id="to_amount"
                                    value={form.data.to_amount}
                                    onChange={handleReceiveAmountChange}
                                    required
                                />
                                <InputError message={form.errors.to_amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="to_currency">
                                    {t('common.currency')}
                                </Label>
                                <Select
                                    value={form.data.to_currency}
                                    onValueChange={(value) =>
                                        handleToCurrencyChange(
                                            value as Currency,
                                        )
                                    }
                                    name="to_currency"
                                >
                                    <SelectTrigger
                                        id="to_currency"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <CurrencyOptions />
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.to_currency} />
                            </div>
                        </div>
                    )}

                    {isExchange && (
                        <div className="grid gap-2">
                            <Label htmlFor="exchange_rate_to_usd">
                                {t('transactions.exchange_rate_pair')}{' '}
                                <bdi
                                    dir="ltr"
                                    className="text-muted-foreground text-xs"
                                >
                                    1 {pair.base} = … {pair.quote}
                                </bdi>
                                {todayRate !== null && (
                                    <span className="text-muted-foreground ms-1 text-xs">
                                        {t('common.current_value', {
                                            value: formatNumber(todayRate, 6),
                                        })}
                                    </span>
                                )}
                            </Label>
                            <MoneyInput
                                id="exchange_rate_to_usd"
                                value={form.data.exchange_rate_to_usd}
                                onChange={handleRateChange}
                                required
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

                    {!isExchange && (
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
                    )}

                    {isDebt && !isExchange && (
                        <div className="grid gap-2">
                            <Label htmlFor="debt_direction">
                                {t('transactions.debt_direction')}
                            </Label>
                            <Select
                                value={form.data.debt_direction}
                                onValueChange={(value) =>
                                    form.setData(
                                        'debt_direction',
                                        value as DebtDirection,
                                    )
                                }
                                name="debt_direction"
                            >
                                <SelectTrigger
                                    id="debt_direction"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="receivable">
                                        {t(
                                            'transactions.debt_direction.receivable',
                                        )}
                                    </SelectItem>
                                    <SelectItem value="payable">
                                        {t(
                                            'transactions.debt_direction.payable',
                                        )}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.debt_direction} />
                        </div>
                    )}

                    {isDebt && !isExchange && (
                        <div className="grid gap-2">
                            <Label htmlFor="debt_debtor_id">
                                {t('common.debtor')}
                            </Label>
                            <Select
                                value={form.data.debt_debtor_id}
                                onValueChange={(value) =>
                                    form.setData('debt_debtor_id', value)
                                }
                                name="debt_debtor_id"
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
                        {t('transactions.save')}
                    </Button>
                </form>
            </div>
        </>
    );
}

TransactionCreate.layout = {
    breadcrumbs: [
        { title: 'nav.transactions', href: index() },
        { title: 'transactions.new', href: '' },
    ],
};
