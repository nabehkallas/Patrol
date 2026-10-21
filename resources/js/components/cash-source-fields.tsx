import { usePage } from '@inertiajs/react';
import { useCurrencies, useCurrencyName } from '@/components/currency-options';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Label } from '@/components/ui/label';
import type { CashSource, CashSourceInput, PayFrom } from '@/lib/cash-source';
import { cashSource } from '@/lib/cash-source';
import { formatMoney, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** What the server shares with every page that has a money-out form (CashBox::formProps()). */
type CashProps = {
    cashBalances?: Record<string, number>;
    cashRates?: Record<string, number | null>;
};

export const emptyCashSource: CashSourceInput = { pay_from: '', cash_rate: '' };

/** The source for a form's amount and currency, from the page's balances and rates. */
export function useCashSource(
    amount: string,
    currency: string,
    input: CashSourceInput,
    giveBack: Record<string, number> = {},
): CashSource {
    const { cashBalances = {}, cashRates = {} } = usePage<CashProps>().props;
    const { currencies, primary } = useCurrencies();
    const decimals =
        currencies.find((c) => c.code === primary)?.decimals ??
        (primary === 'SYP' ? 0 : 2);

    return cashSource(
        amount,
        currency,
        input,
        primary,
        cashBalances,
        cashRates,
        decimals,
        giveBack,
    );
}

/**
 * "مصدر المبلغ" for money paid out in a currency other than the primary one: that currency's
 * box (only when it holds enough), or the primary box at a rate, pre-filled with today's and
 * editable, with the total worked out live. Nothing shows for the primary currency.
 */
export function CashSourceFields({
    amount,
    currency,
    value,
    onChange,
    giveBack,
    errors = {},
}: {
    amount: string;
    currency: string;
    value: CashSourceInput;
    onChange: (value: CashSourceInput) => void;
    giveBack?: Record<string, number>;
    errors?: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const { primary } = useCurrencies();
    const currencyName = useCurrencyName();
    const source = useCashSource(amount, currency, value, giveBack);

    if (!source.applies) {
        return null;
    }

    const choose = (payFrom: PayFrom) =>
        onChange({ ...value, pay_from: payFrom });
    const option = (payFrom: PayFrom, disabled: boolean) =>
        cn(
            'flex w-full flex-col gap-0.5 rounded-md border px-3 py-2 text-start text-sm transition-colors',
            source.payFrom === payFrom
                ? 'border-primary bg-primary/5 ring-primary/40 ring-1'
                : 'border-input hover:bg-muted/60',
            disabled && 'cursor-not-allowed opacity-60 hover:bg-transparent',
        );
    const amountValue = parseFloat(amount);

    return (
        <div className="grid gap-2" data-test="cash-source">
            <Label>{t('cash_source.title')}</Label>
            <div
                className="grid gap-2 sm:grid-cols-2"
                role="radiogroup"
                aria-label={t('cash_source.title')}
            >
                <button
                    type="button"
                    role="radio"
                    aria-checked={source.payFrom === 'own'}
                    disabled={!source.enough}
                    className={option('own', !source.enough)}
                    onClick={() => choose('own')}
                    data-test="cash-source-own"
                >
                    <span className="font-medium">
                        {t('cash_source.box', {
                            box: currencyName(currency),
                        })}
                    </span>
                    <span
                        className={cn(
                            'text-xs',
                            source.enough
                                ? 'text-muted-foreground'
                                : 'text-destructive',
                        )}
                    >
                        {t('cash_source.available', {
                            amount: formatMoney(source.available, currency),
                        })}
                        {!source.enough && ` — ${t('cash_source.not_enough')}`}
                    </span>
                </button>
                <button
                    type="button"
                    role="radio"
                    aria-checked={source.payFrom === 'primary'}
                    className={option('primary', false)}
                    onClick={() => choose('primary')}
                    data-test="cash-source-primary"
                >
                    <span className="font-medium">
                        {t('cash_source.box_at_rate', {
                            box: currencyName(primary),
                        })}
                    </span>
                    <span className="text-muted-foreground text-xs">
                        {t('cash_source.recorded_in', { currency: primary })}
                    </span>
                </button>
            </div>
            {source.payFrom === 'primary' && (
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    {/* "1 USD = [139] SYP" reads left to right whatever the page direction. */}
                    <span dir="ltr" className="inline-flex items-center gap-2">
                        <span dir="ltr" className="whitespace-nowrap">
                            1 {currency} =
                        </span>
                        <MoneyInput
                            dir="ltr"
                            className="h-8 w-28 text-end tabular-nums"
                            aria-label={t('cash_source.rate')}
                            value={
                                value.cash_rate !== ''
                                    ? value.cash_rate
                                    : source.rate !== null
                                      ? String(source.rate)
                                      : ''
                            }
                            onChange={(rate) =>
                                onChange({ ...value, cash_rate: rate })
                            }
                            data-test="cash-source-rate"
                        />
                        <span className="whitespace-nowrap">{primary}</span>
                    </span>
                    {source.converted !== null && source.rate !== null && (
                        <bdi
                            dir="ltr"
                            className="text-muted-foreground whitespace-nowrap tabular-nums"
                            data-test="cash-source-total"
                        >
                            {formatNumber(amountValue, 2)} {currency} ×{' '}
                            {formatNumber(source.rate, 6)} ={' '}
                            <span className="text-foreground font-semibold">
                                {formatMoney(source.converted, primary)}
                            </span>
                        </bdi>
                    )}
                </div>
            )}
            <InputError message={errors.cash_rate} />
        </div>
    );
}

/** "(50 USD @ 139)" after the amount of a record paid from the primary box at a rate. */
export function OriginalPaymentNote({
    original,
}: {
    original:
        | { amount: number | string; currency: string; rate: number | string }
        | null
        | undefined;
}) {
    if (!original) {
        return null;
    }

    return (
        <bdi
            dir="ltr"
            className="text-muted-foreground ms-1 whitespace-nowrap text-xs tabular-nums"
            data-test="original-payment"
        >
            ({formatMoney(original.amount, original.currency)} @{' '}
            {formatNumber(Number(original.rate), 6)})
        </bdi>
    );
}
