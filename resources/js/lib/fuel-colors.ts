import { usePage } from '@inertiajs/react';

/**
 * Fuel type colours. Each fuel type stores its own colour (see App\Support\FuelColors), shared with
 * every page as `fuelTypeColors`; these are the classes for each one. Class names are written out
 * in full so Tailwind finds them.
 */
export const FUEL_COLORS = [
    'amber',
    'blue',
    'emerald',
    'violet',
    'rose',
    'cyan',
    'lime',
    'fuchsia',
] as const;

export type FuelColor = (typeof FUEL_COLORS)[number];

export type FuelColorStyle = {
    /** Small dot in dropdowns and pickers. */
    dot: string;
    /** Top edge of a card. */
    borderTop: string;
    /** Start edge of a row or card. */
    borderStart: string;
    /** Tinted badge (pump names, sales totals). */
    badge: string;
    /** Coloured text (figures in reports). */
    text: string;
    /** Chart line / bar colour. */
    chart: string;
};

const STYLES: Record<FuelColor, FuelColorStyle> = {
    amber: {
        dot: 'bg-amber-500',
        borderTop: 'border-t-amber-500',
        borderStart: 'border-s-amber-500',
        badge: 'bg-amber-50 text-amber-900 ring-amber-300 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/40',
        text: 'text-amber-600 dark:text-amber-400',
        chart: 'var(--chart-1)',
    },
    blue: {
        dot: 'bg-blue-500',
        borderTop: 'border-t-blue-500',
        borderStart: 'border-s-blue-500',
        badge: 'bg-blue-50 text-blue-900 ring-blue-300 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-500/40',
        text: 'text-blue-600 dark:text-blue-400',
        chart: 'var(--chart-2)',
    },
    emerald: {
        dot: 'bg-emerald-500',
        borderTop: 'border-t-emerald-500',
        borderStart: 'border-s-emerald-500',
        badge: 'bg-emerald-50 text-emerald-900 ring-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-500/40',
        text: 'text-emerald-600 dark:text-emerald-400',
        chart: 'var(--chart-3)',
    },
    violet: {
        dot: 'bg-violet-500',
        borderTop: 'border-t-violet-500',
        borderStart: 'border-s-violet-500',
        badge: 'bg-violet-50 text-violet-900 ring-violet-300 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-500/40',
        text: 'text-violet-600 dark:text-violet-400',
        chart: 'var(--chart-4)',
    },
    rose: {
        dot: 'bg-rose-500',
        borderTop: 'border-t-rose-500',
        borderStart: 'border-s-rose-500',
        badge: 'bg-rose-50 text-rose-900 ring-rose-300 dark:bg-rose-500/15 dark:text-rose-200 dark:ring-rose-500/40',
        text: 'text-rose-600 dark:text-rose-400',
        chart: '#f43f5e',
    },
    cyan: {
        dot: 'bg-cyan-500',
        borderTop: 'border-t-cyan-500',
        borderStart: 'border-s-cyan-500',
        badge: 'bg-cyan-50 text-cyan-900 ring-cyan-300 dark:bg-cyan-500/15 dark:text-cyan-200 dark:ring-cyan-500/40',
        text: 'text-cyan-600 dark:text-cyan-400',
        chart: '#06b6d4',
    },
    lime: {
        dot: 'bg-lime-500',
        borderTop: 'border-t-lime-500',
        borderStart: 'border-s-lime-500',
        badge: 'bg-lime-50 text-lime-900 ring-lime-300 dark:bg-lime-500/15 dark:text-lime-200 dark:ring-lime-500/40',
        text: 'text-lime-600 dark:text-lime-400',
        chart: '#84cc16',
    },
    fuchsia: {
        dot: 'bg-fuchsia-500',
        borderTop: 'border-t-fuchsia-500',
        borderStart: 'border-s-fuchsia-500',
        badge: 'bg-fuchsia-50 text-fuchsia-900 ring-fuchsia-300 dark:bg-fuchsia-500/15 dark:text-fuchsia-200 dark:ring-fuchsia-500/40',
        text: 'text-fuchsia-600 dark:text-fuchsia-400',
        chart: '#d946ef',
    },
};

/** For a fuel type with no colour (shouldn't happen once saved): plain, neutral styling. */
const NEUTRAL: FuelColorStyle = {
    dot: 'bg-slate-400',
    borderTop: 'border-t-border',
    borderStart: 'border-s-border',
    badge: 'bg-slate-100 text-slate-700 ring-slate-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600',
    text: 'text-foreground',
    chart: 'var(--chart-5)',
};

export function fuelColorStyle(
    color: string | null | undefined,
): FuelColorStyle {
    return STYLES[color as FuelColor] ?? NEUTRAL;
}

type SharedFuelType = { id: number; name: string; color: string | null };

/** Look up a fuel type's colour classes by its id or its name, from the shared colours. */
export function useFuelColors() {
    const list =
        usePage<{ fuelTypeColors?: SharedFuelType[] }>().props.fuelTypeColors ??
        [];

    return {
        byId: (id: number | null | undefined) =>
            fuelColorStyle(list.find((f) => f.id === id)?.color),
        byName: (name: string | null | undefined) =>
            fuelColorStyle(list.find((f) => f.name === name)?.color),
    };
}
