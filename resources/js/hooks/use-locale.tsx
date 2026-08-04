import { useSyncExternalStore } from 'react';

export type Locale = 'en' | 'ar';
export type Direction = 'ltr' | 'rtl';

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

const getStoredLocale = (): Locale => {
    if (typeof window === 'undefined') {
        return 'en';
    }

    return (localStorage.getItem('locale') as Locale) || 'en';
};

export const directionFor = (locale: Locale): Direction => (locale === 'ar' ? 'rtl' : 'ltr');

const applyLocale = (locale: Locale): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = locale;
    document.documentElement.dir = directionFor(locale);
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

    if (!localStorage.getItem('locale')) {
        localStorage.setItem('locale', 'en');
        setCookie('locale', 'en');
    }

    currentLocale = getStoredLocale();
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
    };

    return { locale, direction: directionFor(locale), updateLocale } as const;
}
