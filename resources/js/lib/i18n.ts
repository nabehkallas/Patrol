import { useEffect, useSyncExternalStore } from 'react';
import type { Locale } from '@/hooks/use-locale';
import { useLocale } from '@/hooks/use-locale';
import ar from '../../../lang/ar.json';
import en from '../../../lang/en.json';

/**
 * Interface texts live in lang/{code}.json, the same files Laravel reads for server messages,
 * emails and exports. English is the reference (every key is defined there) and the fallback
 * for anything missing. English and Arabic ship with the app; the other languages are fetched
 * the first time they are chosen.
 */
export type TranslationKey = keyof typeof en;

type Messages = Record<string, string>;

const messages: Partial<Record<Locale, Messages>> = { en, ar };

const loaders: Partial<Record<Locale, () => Promise<{ default: Messages }>>> = {
    tr: () => import('../../../lang/tr.json'),
    fr: () => import('../../../lang/fr.json'),
    ku: () => import('../../../lang/ku.json'),
};

const pending = new Map<Locale, Promise<void>>();
const listeners = new Set<() => void>();

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

export function loadMessages(locale: Locale): Promise<void> {
    const loader = loaders[locale];

    if (messages[locale] || !loader) {
        return Promise.resolve();
    }

    if (!pending.has(locale)) {
        pending.set(
            locale,
            loader()
                .then((module) => {
                    messages[locale] = module.default;
                    listeners.forEach((listener) => listener());
                })
                .catch(() => {
                    // Offline or a stale deploy: stay on English and retry next time.
                    pending.delete(locale);
                }),
        );
    }

    return pending.get(locale)!;
}

export function useTranslation() {
    const { locale } = useLocale();

    // The active language's texts; English until a language fetched on demand has arrived.
    // Reading them through the store (not the module variable) re-renders on arrival, and gives
    // the React Compiler a dependency to memoise `t` on.
    const active = useSyncExternalStore(
        subscribe,
        () => messages[locale] ?? en,
        () => messages[locale] ?? en,
    );

    useEffect(() => {
        void loadMessages(locale);
    }, [locale]);

    function t(key: TranslationKey): string {
        return active[key] ?? en[key] ?? key;
    }

    return { t, locale };
}
