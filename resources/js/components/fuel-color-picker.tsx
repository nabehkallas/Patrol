import { Check } from 'lucide-react';
import { FUEL_COLORS, fuelColorStyle } from '@/lib/fuel-colors';
import type { FuelColor } from '@/lib/fuel-colors';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Choose a fuel type's colour from the palette. Colours another fuel type already has are marked,
 * so the station can keep each fuel type distinct.
 */
export function FuelColorPicker({
    value,
    onChange,
    usedByOthers = [],
}: {
    value: string;
    onChange: (color: FuelColor) => void;
    usedByOthers?: string[];
}) {
    const { t } = useTranslation();

    return (
        <div
            role="radiogroup"
            aria-label={t('fuel_types.color')}
            className="flex flex-wrap gap-2"
        >
            {FUEL_COLORS.map((color) => {
                const selected = value === color;
                const taken = usedByOthers.includes(color);

                return (
                    <button
                        key={color}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        aria-label={t(`fuel_types.color_${color}`)}
                        title={
                            taken
                                ? `${t(`fuel_types.color_${color}`)} — ${t('fuel_types.color_taken')}`
                                : t(`fuel_types.color_${color}`)
                        }
                        data-test={`fuel-color-${color}`}
                        onClick={() => onChange(color)}
                        className={cn(
                            'focus-visible:ring-ring/50 relative flex size-9 items-center justify-center rounded-full outline-none focus-visible:ring-[3px]',
                            fuelColorStyle(color).dot,
                            selected &&
                                'ring-foreground ring-offset-background ring-2 ring-offset-2',
                            taken && !selected && 'opacity-40',
                        )}
                    >
                        {selected && <Check className="size-4 text-white" />}
                    </button>
                );
            })}
        </div>
    );
}
