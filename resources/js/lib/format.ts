import type { Currency, CurrencyBreakdown } from '@/types';

/**
 * Formats a number (or a Laravel decimal-cast numeric string) with up to `digits` decimals,
 * dropping trailing zeros: 50000 litres read "50,000", not "50,000.0". Always Western digits
 * and en-US grouping, whatever the browser's or the app's language, so figures look the same
 * everywhere (and line up with what people type into the number fields).
 */
export function formatNumber(value: string | number, digits = 1): string {
    const num = typeof value === 'string' ? parseFloat(value) : value;

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    }).format(Number.isFinite(num) ? num : 0);
}

/** A stored decimal ("1500.0000", "5.000000") as a plain value for a form field ("1500", "5"). */
export function trimDecimal(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const num = typeof value === 'string' ? parseFloat(value) : value;

    return Number.isFinite(num) ? String(num) : String(value);
}

let currencyDecimals: Record<string, number> | null = null;

/** Keeps the cached decimals current when the station's currencies change. */
export function setCurrencies(currencies: unknown): void {
    if (!Array.isArray(currencies)) {
        currencyDecimals ??= {};

        return;
    }

    currencyDecimals = Object.fromEntries(
        currencies
            .filter((c) => typeof c?.code === 'string')
            .map((c) => [c.code as string, Number(c.decimals ?? 2)]),
    );
}

/**
 * How many decimals a currency is shown with (Settings > Currencies), from the `currencies`
 * page prop: SYP is usually 0, USD 2.
 */
function decimalsFor(currency: string): number {
    if (currencyDecimals === null) {
        try {
            const page = document.querySelector('script[data-page="app"]');
            setCurrencies(
                JSON.parse(page?.textContent ?? '{}').props?.currencies,
            );
        } catch {
            currencyDecimals = {};
        }
    }

    return currencyDecimals?.[currency] ?? (currency === 'SYP' ? 0 : 2);
}

/** An amount and its currency code, with that currency's decimals: "2,670,000 SYP", "12.50 TRY". */
export function formatMoney(
    amount: string | number,
    currency: Currency,
): string {
    const value = typeof amount === 'string' ? parseFloat(amount) : amount;
    const digits = decimalsFor(currency);
    const number = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(Number.isFinite(value) ? value : 0);

    return `${number} ${currency}`;
}

export function formatUsd(amount: number): string {
    return amount.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}

export function formatSyp(amount: number): string {
    return formatMoney(amount, 'SYP');
}

export function formatCurrencyAmount(
    amount: string | number,
    currency: Currency,
): string {
    if (currency === 'USD') {
        return formatUsd(
            typeof amount === 'string' ? parseFloat(amount) : amount,
        );
    }

    return formatMoney(amount, currency);
}

/**
 * Joins every non-zero currency in a breakdown into one string (e.g. "1,000 SYP + 50.00 USD") —
 * the primary currency is always included even at zero, matching CurrencyCard's convention of
 * always showing a
 * primary figure.
 */
/**
 * Like formatBreakdown, but lists only the currencies that actually have an amount (so a debt
 * in dollars alone doesn't read "0.0 SYP + $20.00"); all zero shows the primary currency's 0.
 */
export function formatNonZeroBreakdown(breakdown: CurrencyBreakdown): string {
    const parts = Object.entries(breakdown)
        .filter(([, amount]) => Math.abs(amount as number) > 0.004)
        .map(([currency, amount]) =>
            formatCurrencyAmount(amount as number, currency as Currency),
        );

    return parts.length > 0 ? parts.join(' + ') : formatPrimary(0);
}

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

let displayLocale = 'en-US';

/** The language dates are written in (month names), kept in step by the locale provider. */
export function setDisplayLocale(intlLocale: string): void {
    displayLocale = intlLocale;
}

/**
 * Dates are written in the user's language ("11 Sep 2026" / "11 سبتمبر 2026") but always with
 * Western digits, like every number in the app, and times on a 24-hour clock.
 */
function dateLocale(): string {
    return `${displayLocale}-u-nu-latn`;
}

/** A date and time, e.g. "11 Sep 2026, 15:25", in station time. */
export function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(dateLocale(), {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: timeZoneFor(value),
    });
}

/** A date, e.g. "11 Sep 2026". A bare calendar date as-is, a timestamp in station time. */
export function formatDate(value: string): string {
    return new Date(value).toLocaleDateString(dateLocale(), {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: timeZoneFor(value),
    });
}

/** 'DD/MM/YYYY' — a bare calendar date as-is, a timestamp in station time. */
export function formatDayMonthYear(value: string): string {
    return new Date(value).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: timeZoneFor(value),
    });
}

/** 'HH:MM' (24-hour) of a timestamp, in station time. */
export function formatTime24(value: string): string {
    return new Date(value).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: getStationTimeZone(),
    });
}

/** The station-time calendar day of a timestamp, as 'YYYY-MM-DD'. */
export function stationDateOf(value: string): string {
    return new Date(value).toLocaleDateString('en-CA', {
        timeZone: getStationTimeZone(),
    });
}

/** Compact "10 Sep" style date, for a breakdown row's sub-line or a chart axis, where a full
 * date (which includes the year) would be too wide. */
export function formatShortDate(value: string): string {
    return new Date(value).toLocaleDateString(dateLocale(), {
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

/**
 * A currency's name in the user's language ("Syrian Pound" reads "ليرة سورية" in Arabic). A
 * name the station typed itself (anything but the standard English name) is shown as typed.
 */
export function currencyName(code: string, storedName: string): string {
    try {
        const english = new Intl.DisplayNames('en', { type: 'currency' }).of(
            code,
        );

        if (
            english &&
            english.toLowerCase() !== storedName.trim().toLowerCase()
        ) {
            return storedName;
        }

        return (
            new Intl.DisplayNames(displayLocale, { type: 'currency' }).of(
                code,
            ) ?? storedName
        );
    } catch {
        return storedName;
    }
}

/** "October 2026" in the user's language, for a calendar's month caption. */
export function formatMonthYear(date: Date): string {
    return new Intl.DateTimeFormat(dateLocale(), {
        month: 'long',
        year: 'numeric',
    }).format(date);
}

/** A one-letter weekday name ("M" / "ن"), for a calendar's narrow column headers. */
export function formatWeekdayShort(date: Date): string {
    return new Intl.DateTimeFormat(dateLocale(), { weekday: 'narrow' }).format(
        date,
    );
}
