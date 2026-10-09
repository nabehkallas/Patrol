<?php

namespace App\Support;

use App\Models\FuelType;

/**
 * The colours a fuel type can have. Each fuel type stores its own (fuel_types.color), so it keeps
 * it when other fuel types are added, removed or renamed. The order is the order new fuel types
 * get them in: the first is amber (usually petrol), the second blue (usually diesel), as before.
 * The frontend has the matching classes in resources/js/lib/fuel-colors.ts.
 */
final class FuelColors
{
    public const PALETTE = ['amber', 'blue', 'emerald', 'violet', 'rose', 'cyan', 'lime', 'fuchsia'];

    /** The first palette colour no fuel type uses yet (or, when all are taken, the least used). */
    public static function nextFree(): string
    {
        $used = FuelType::query()->whereNotNull('color')->pluck('color')->countBy();

        return collect(self::PALETTE)
            ->sortBy(fn (string $color, int $i) => [$used->get($color, 0), $i])
            ->first();
    }
}
