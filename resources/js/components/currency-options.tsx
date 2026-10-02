import { usePage } from '@inertiajs/react';
import { SelectItem } from '@/components/ui/select';
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
