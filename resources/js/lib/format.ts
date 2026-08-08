import type { Currency } from '@/types';

/**
 * Formats a number (or a Laravel decimal-cast numeric string) with a fixed
 * number of decimal digits, using a consistent 'en-US' locale regardless of
 * the browser's locale or the app's ar/en language toggle.
 */
export function formatNumber(value: string | number, digits = 1): string {
    const num = typeof value === 'string' ? parseFloat(value) : value;

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(num);
}

export function formatMoney(
    amount: string | number,
    currency: Currency,
): string {
    const value = typeof amount === 'string' ? parseFloat(amount) : amount;

    return `${value.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ${currency}`;
}

export function formatUsd(amount: number): string {
    return amount.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}

export function formatSyp(amount: number): string {
    return formatNumber(amount, 1) + ' SYP';
}

export function formatCurrencyAmount(
    amount: number,
    currency: Currency,
): string {
    if (currency === 'SYP') {
        return formatSyp(amount);
    }

    if (currency === 'USD') {
        return formatUsd(amount);
    }

    return formatMoney(amount, currency);
}

/**
 * Formats a date/time using a fixed 'en-US' locale regardless of the browser's own locale —
 * otherwise a browser set to Arabic renders these with Arabic-Indic digits and a different
 * layout than the rest of the app (which always shows Western numerals, see formatNumber
 * above), making the two look inconsistent/broken side by side.
 */
export function formatDateTime(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('en-US', {
        dateStyle: 'medium',
    });
}

/**
 * Strips everything but digits and a single decimal point from user input,
 * so typed thousand separators (or stray characters) never reach form state.
 */
export function stripNumberInputFormatting(value: string): string {
    const cleaned = value.replace(/[^\d.]/g, '');
    const firstDot = cleaned.indexOf('.');

    if (firstDot === -1) {
        return cleaned;
    }

    return (
        cleaned.slice(0, firstDot + 1) +
        cleaned.slice(firstDot + 1).replace(/\./g, '')
    );
}

/** Groups the integer part of a plain numeric string with thousand separators. */
export function formatNumberWithCommas(value: string): string {
    if (value === '') {
        return '';
    }

    const [integerPart, decimalPart] = value.split('.');
    const groupedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return decimalPart !== undefined
        ? `${groupedInteger}.${decimalPart}`
        : groupedInteger;
}
