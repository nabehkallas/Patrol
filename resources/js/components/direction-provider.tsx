import { DirectionProvider } from '@radix-ui/react-direction';
import type { ReactNode } from 'react';
import { useLocale } from '@/hooks/use-locale';

/**
 * Radix primitives (select, dropdown menu, …) stamp their own `dir` attribute from this
 * context and default to "ltr", which overrides the page direction. Feeding them the active
 * language's direction keeps dropdown values, placeholders and options right-aligned in Arabic.
 */
export function LocaleDirectionProvider({ children }: { children: ReactNode }) {
    const { direction } = useLocale();

    return <DirectionProvider dir={direction}>{children}</DirectionProvider>;
}
