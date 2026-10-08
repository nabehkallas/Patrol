import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { setDisplayLocale } from '@/lib/format';

export type Direction = 'ltr' | 'rtl';

/**
 * The interface languages. Their texts live in lang/{code}.json; keep this list in step with
 * App\Support\Locales on the server. `intl` is the locale used for month and day names.
 */
export const LOCALES = {
    ar: { name: 'العربية', dir: 'rtl', intl: 'ar-SY' },
    en: { name: 'English', dir: 'ltr', intl: 'en-US' },
    fr: { name: 'Français', dir: 'ltr', intl: 'fr-FR' },
    es: { name: 'Español', dir: 'ltr', intl: 'es-ES' },
    de: { name: 'Deutsch', dir: 'ltr', intl: 'de-DE' },
    it: { name: 'Italiano', dir: 'ltr', intl: 'it-IT' },
    tr: { name: 'Türkçe', dir: 'ltr', intl: 'tr-TR' },
    ru: { name: 'Русский', dir: 'ltr', intl: 'ru-RU' },
    ur: { name: 'اردو', dir: 'rtl', intl: 'ur-PK' },
    hi: { name: 'हिन्दी', dir: 'ltr', intl: 'hi-IN' },
    // Northern Kurdish (Kurmanji) in the Latin script, as spoken in Syria.
    ku: { name: 'Kurdî (Kurmancî)', dir: 'ltr', intl: 'ku' },
} as const satisfies Record<
    string,
    { name: string; dir: Direction; intl: string }
>;

export type Locale = keyof typeof LOCALES;

export const SUPPORTED_LOCALES = Object.keys(LOCALES) as Locale[];

export type UseLocaleReturn = {
    readonly locale: Locale;
    readonly direction: Direction;
    readonly updateLocale: (locale: Locale) => void;
};

const listeners = new Set<() => void>();
let currentLocale: Locale = 'en';

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

export const isLocale = (value: unknown): value is Locale =>
    typeof value === 'string' &&
    (SUPPORTED_LOCALES as string[]).includes(value);

const getStoredLocale = (): Locale => {
    if (typeof window === 'undefined') {
        return 'en';
    }

    const stored = localStorage.getItem('locale');

    return isLocale(stored) ? stored : 'en';
};

export const directionFor = (locale: Locale): Direction => LOCALES[locale].dir;

const applyLocale = (locale: Locale): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = locale;
    document.documentElement.dir = directionFor(locale);
    setDisplayLocale(LOCALES[locale].intl);
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeLocale(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentLocale = getStoredLocale();

    // Keeps storage and the cookie (which the server reads for emails, messages and exports)
    // valid and in sync, e.g. after a language was dropped or the cookie expired.
    localStorage.setItem('locale', currentLocale);
    setCookie('locale', currentLocale);

    applyLocale(currentLocale);
}

export function useLocale(): UseLocaleReturn {
    const locale: Locale = useSyncExternalStore(
        subscribe,
        () => currentLocale,
        () => 'en',
    );

    const updateLocale = (locale: Locale): void => {
        currentLocale = locale;

        localStorage.setItem('locale', locale);
        setCookie('locale', locale);

        applyLocale(locale);
        notify();

        // Fresh page data, so dates already on screen and texts the server wrote (flash
        // messages, labels) switch language too.
        if (document.querySelector('script[data-page="app"]')) {
            router.reload();
        }
    };

    return { locale, direction: directionFor(locale), updateLocale } as const;
}
