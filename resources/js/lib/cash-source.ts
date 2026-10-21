/**
 * Where money paid out in a currency other than the primary one comes from: that currency's own
 * box (only when it holds enough), or the primary-currency box at a rate ("1 USD = 139 SYP"),
 * which then records what left that box. The server checks and stores it (App\Services\CashBox);
 * this works out what the form shows. Kept free of imports so `npm run test:js` can run it.
 */

export type PayFrom = 'own' | 'primary';

/** The form's part: the choice ('' = not chosen yet) and the rate as typed ('' = today's). */
export type CashSourceInput = {
    pay_from: '' | PayFrom;
    cash_rate: string;
};

export type CashSource = {
    /** Another currency than the primary one: the choice is shown. */
    applies: boolean;
    /** What that currency's box holds, plus what the record being edited took out of it. */
    available: number;
    enough: boolean;
    payFrom: PayFrom;
    /** The rate in use: as typed, else today's saved one; null when there is neither. */
    rate: number | null;
    /** The amount in the primary currency at that rate, rounded to its decimals. */
    converted: number | null;
};

/** Today's rate as "1 currency = X primary", from saved rates in units per 1 USD. */
export function conversionRate(
    currency: string,
    primary: string,
    ratesPerUsd: Record<string, number | null | undefined>,
): number | null {
    const perUsd = (code: string) =>
        code === 'USD' ? 1 : (ratesPerUsd[code] ?? null);
    const from = perUsd(currency);
    const to = perUsd(primary);

    return from && to ? to / from : null;
}

/**
 * The source as the form stands: chosen, or by default the currency's own box when it holds
 * enough and the primary box otherwise.
 */
export function cashSource(
    amount: string,
    currency: string,
    input: CashSourceInput,
    primary: string,
    balances: Record<string, number>,
    ratesPerUsd: Record<string, number | null | undefined>,
    primaryDecimals: number,
    giveBack: Record<string, number> = {},
): CashSource {
    const value = parseFloat(amount);
    const available = (balances[currency] ?? 0) + (giveBack[currency] ?? 0);
    const enough = !Number.isFinite(value) || value <= available + 0.005;
    const applies = currency !== primary;
    const typed = parseFloat(input.cash_rate);
    const rate =
        Number.isFinite(typed) && typed > 0
            ? typed
            : conversionRate(currency, primary, ratesPerUsd);
    const factor = 10 ** primaryDecimals;
    const converted =
        rate !== null && Number.isFinite(value) && value > 0
            ? Math.round(value * rate * factor) / factor
            : null;

    return {
        applies,
        available,
        enough,
        payFrom: !applies
            ? 'own'
            : input.pay_from || (enough ? 'own' : 'primary'),
        rate,
        converted,
    };
}

/** What the form sends: the source, and the rate when paying from the primary box. */
export function cashSourcePayload(source: CashSource): {
    pay_from: PayFrom;
    cash_rate: number | null;
} {
    return {
        pay_from: source.payFrom,
        cash_rate: source.payFrom === 'primary' ? source.rate : null,
    };
}
