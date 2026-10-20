/**
 * An amount worked out from a price (quantity × price) -- only in the price's own currency.
 * In any other currency there is nothing to fill: the amount is typed, never converted with
 * an exchange rate. Null when it can't be worked out.
 */
export function priceAmount(
    quantity: string,
    price: string | number | null | undefined,
    priceCurrency: string | null | undefined,
    currency: string,
): string | null {
    if (price === null || price === undefined || price === '') {
        return null;
    }

    if (!priceCurrency || priceCurrency !== currency) {
        return null;
    }

    const amount = parseFloat(quantity) * Number(price);

    return Number.isFinite(amount) && amount > 0 ? amount.toFixed(2) : null;
}

/** A form's quantity, currency and amount, and whether that amount was typed. */
export type PriceFilledAmount = {
    quantity: string;
    currency: string;
    amount: string;
    amountTyped: boolean;
};

/**
 * The amount after the quantity or currency changed: a typed amount is never changed;
 * otherwise it's filled from the price in the price's own currency, and cleared in another.
 */
export function refillAmount(
    form: PriceFilledAmount,
    price: string | number | null | undefined,
    priceCurrency: string | null | undefined,
): string {
    if (form.amountTyped) {
        return form.amount;
    }

    return (
        priceAmount(form.quantity, price, priceCurrency, form.currency) ?? ''
    );
}
