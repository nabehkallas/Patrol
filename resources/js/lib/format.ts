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

    return `${value.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ${currency}`;
}

export function formatUsd(amount: number): string {
    return amount.toLocaleString(undefined, {
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

export function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function formatDate(value: string): string {
    return new Date(value).toLocaleDateString(undefined, {
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
