import type { Currency, CurrencyBreakdown } from '@/types';

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
 * Joins every non-zero currency in a breakdown into one string (e.g. "1,000 SYP + 50.00 USD") —
 * the primary currency is always included even at zero, matching CurrencyCard's convention of
 * always showing a
 * primary figure.
 */
export function formatBreakdown(breakdown: CurrencyBreakdown): string {
    return Object.entries(breakdown)
        .filter(
            ([currency, amount]) =>
                currency === getPrimaryCurrency() || amount !== 0,
        )
        .map(([currency, amount]) =>
            formatCurrencyAmount(amount as number, currency as Currency),
        )
        .join(' + ');
}

let stationTimeZone: string | null = null;

/**
 * The station's timezone (shared by the server as the `timezone` page prop), so times and
 * "today" are always station time rather than whatever timezone the viewer's device is in.
 * Read from the initial page data, which is in the document before any component renders.
 */
export function getStationTimeZone(): string {
    if (stationTimeZone === null) {
        try {
            const page = document.querySelector('script[data-page="app"]');
            stationTimeZone =
                JSON.parse(page?.textContent ?? '{}').props?.timezone ??
                'Asia/Damascus';
        } catch {
            stationTimeZone = 'Asia/Damascus';
        }
    }

    return stationTimeZone as string;
}

let primaryCurrency: string | null = null;

/**
 * The station's primary currency (Settings > Currencies), shared by the server as the
 * `primaryCurrency` page prop: what reports are shown in and what new entries default to.
 */
export function getPrimaryCurrency(): string {
    if (primaryCurrency === null) {
        try {
            const page = document.querySelector('script[data-page="app"]');
            primaryCurrency =
                JSON.parse(page?.textContent ?? '{}').props?.primaryCurrency ??
                'SYP';
        } catch {
            primaryCurrency = 'SYP';
        }
    }

    return primaryCurrency as string;
}

export function setPrimaryCurrency(code: unknown): void {
    if (typeof code === 'string' && code !== '') {
        primaryCurrency = code;
    }
}

/** An amount in the station's primary currency, e.g. "1,250.0 SYP" or "$1,250.00". */
export function formatPrimary(amount: number): string {
    return formatCurrencyAmount(amount, getPrimaryCurrency());
}

/** Keeps the cached zone current when the station's timezone setting changes mid-session. */
export function setStationTimeZone(timeZone: unknown): void {
    if (typeof timeZone === 'string' && timeZone !== '') {
        stationTimeZone = timeZone;
    }
}

/** A bare 'YYYY-MM-DD' is a calendar date with no time or zone -- format it as-is (pinned to
 * UTC, where JS parses it) instead of shifting it into any timezone, which could change the day. */
function timeZoneFor(value: string): string {
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? 'UTC' : getStationTimeZone();
}

/**
 * Formats a date/time using a fixed 'en-US' locale regardless of the browser's own locale —
 * otherwise a browser set to Arabic renders these with Arabic-Indic digits and a different
 * layout than the rest of the app (which always shows Western numerals, see formatNumber
 * above), making the two look inconsistent/broken side by side. Shown in station time.
 */
export function formatDateTime(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timeZoneFor(value),
    });
}

export function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('en-US', {
        dateStyle: 'medium',
        timeZone: timeZoneFor(value),
    });
}

/** Compact "Sep 10" style date, for a breakdown row's sub-line where a full medium date
 * (which includes the year) would be too wide. Fixed 'en-US' locale for the same reason
 * as formatDate/formatDateTime above. */
export function formatShortDate(value: string): string {
    return new Date(value).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        timeZone: timeZoneFor(value),
    });
}

/**
 * Today's date in station time as 'YYYY-MM-DD' (optionally shifted by whole days, e.g. -1 for
 * yesterday). Use this instead of `toISOString().slice(0, 10)`, which gives the UTC date --
 * still the previous day for the first hours after midnight east of UTC.
 */
export function todayInStation(offsetDays = 0): string {
    const date = new Date(Date.now() + offsetDays * 86_400_000);

    // en-CA formats as YYYY-MM-DD.
    return date.toLocaleDateString('en-CA', {
        timeZone: getStationTimeZone(),
    });
}

/** The first day of the current station-time month, as 'YYYY-MM-DD'. */
export function startOfMonthInStation(): string {
    return `${todayInStation().slice(0, 8)}01`;
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
