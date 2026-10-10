import type { Currency } from '@/types';

/**
 * The currency exchange operation (تحويل العملة) is the only place an exchange rate is used.
 * The rate entered on the form is quoted as "1 base = rate quote": with USD on either side the
 * base is USD ("1 USD = 135 SYP"), otherwise it's the currency given. The amount received is
 * worked out from that rate, and that same rate is stored on the exchange record.
 */
export function ratePair(
    from: Currency,
    to: Currency,
): { base: Currency; quote: Currency } {
    if (to === 'USD') {
        return { base: 'USD', quote: from };
    }

    if (from === 'USD') {
        return { base: 'USD', quote: to };
    }

    return { base: from, quote: to };
}

/** Today's rate for the pair, from the station's saved rates (each "units per 1 USD"). */
export function defaultRate(
    from: Currency,
    to: Currency,
    rates: Record<Currency, number>,
): number | null {
    const perUsd = (currency: Currency) =>
        currency === 'USD' ? 1 : (rates[currency] ?? null);
    const { base, quote } = ratePair(from, to);
    const baseRate = perUsd(base);
    const quoteRate = perUsd(quote);

    return baseRate && quoteRate ? quoteRate / baseRate : null;
}

function valid(value: number): boolean {
    return Number.isFinite(value) && value > 0;
}

/** What you receive for giving `amount` of `from`, at `rate`. */
export function receiveFor(
    amount: number,
    from: Currency,
    to: Currency,
    rate: number,
): number | null {
    if (!valid(amount) || !valid(rate) || from === to) {
        return null;
    }

    return ratePair(from, to).base === from ? amount * rate : amount / rate;
}

/** What you give of `from` to receive `toAmount` of `to`, at `rate`. */
export function giveFor(
    toAmount: number,
    from: Currency,
    to: Currency,
    rate: number,
): number | null {
    if (!valid(toAmount) || !valid(rate) || from === to) {
        return null;
    }

    return ratePair(from, to).base === from ? toAmount / rate : toAmount * rate;
}
