import { usePage } from '@inertiajs/react';
import { SelectItem } from '@/components/ui/select';
import { useTranslation } from '@/lib/i18n';
import type { StationCurrency } from '@/types';

/** The station's currencies (shared by the server on every station page). */
export function useCurrencies(): {
    currencies: StationCurrency[];
    active: StationCurrency[];
    primary: string;
} {
    const { currencies = [], primaryCurrency = 'SYP' } = usePage<{
        currencies?: StationCurrency[];
        primaryCurrency?: string;
    }>().props;

    return {
        currencies,
        active: currencies.filter((c) => c.is_active),
        primary: primaryCurrency,
    };
}

/** A currency's name for hints ("الليرة السورية"): translated if known, else the station's. */
export function useCurrencyName(): (code: string) => string {
    const { t } = useTranslation();
    const { currencies } = useCurrencies();
    const known: Record<string, string> = {
        SYP: t('currency.syp'),
        USD: t('currency.usd'),
        TRY: t('currency.try'),
    };

    return (code) =>
        known[code] ??
        currencies.find((currency) => currency.code === code)?.name ??
        code;
}

/**
 * <SelectItem>s for the station's active currencies, primary first. `include` keeps a value
 * that is no longer active selectable (e.g. editing an old record in a retired currency);
 * `exclude` drops codes (e.g. USD on the exchange-rate form, since every rate is per USD).
 */
export function CurrencyOptions({
    include,
    exclude = [],
}: {
    include?: string | null;
    exclude?: string[];
}) {
    const { active } = useCurrencies();
    const codes = active.map((c) => c.code);

    if (include && !codes.includes(include)) {
        codes.push(include);
    }

    return (
        <>
            {codes
                .filter((code) => !exclude.includes(code))
                .map((code) => (
                    <SelectItem key={code} value={code}>
                        {code}
                    </SelectItem>
                ))}
        </>
    );
}
