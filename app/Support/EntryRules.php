<?php

namespace App\Support;

use App\Models\User;
use App\Rules\EntryDate;
use Illuminate\Contracts\Validation\Validator;

/**
 * Validation pieces every form that records money or fuel shares: the date it's entered for,
 * upper bounds on amounts and notes, and the liters × price check.
 */
final class EntryRules
{
    /** The largest amount, price or rate any form accepts. */
    public const MAX_AMOUNT = 1_000_000_000_000;

    public const MAX_NOTES = 1000;

    /** @return list<mixed> */
    public static function date(?User $user): array
    {
        return ['date', new EntryDate($user)];
    }

    /** @return list<string> */
    public static function amount(string $min = '0.01'): array
    {
        return ['numeric', 'min:'.$min, 'max:'.self::MAX_AMOUNT];
    }

    /** @return list<string> */
    public static function notes(): array
    {
        return ['nullable', 'string', 'max:'.self::MAX_NOTES];
    }

    /**
     * Whether $amount is liters × price per liter, give or take rounding: within one whole
     * currency unit or 0.1% of the product, whichever is larger (amounts rounded to whole
     * units, prices rounded to 3 decimals).
     */
    public static function matchesLitersTimesPrice(mixed $liters, mixed $pricePerLiter, mixed $amount): bool
    {
        if (! is_numeric($liters) || ! is_numeric($pricePerLiter) || ! is_numeric($amount)) {
            return true; // the field rules report missing or non-numeric values
        }

        $product = (float) $liters * (float) $pricePerLiter;

        return abs($product - (float) $amount) <= max(1.0, 0.001 * $product);
    }

    /** Adds the "doesn't match liters × price" error to $field when it doesn't. */
    public static function checkLitersTimesPrice(Validator $validator, mixed $liters, mixed $pricePerLiter, mixed $amount, string $field = 'amount'): void
    {
        if (! self::matchesLitersTimesPrice($liters, $pricePerLiter, $amount)) {
            $validator->errors()->add($field, __('The amount must equal liters × price per liter (:expected).', [
                'expected' => number_format((float) $liters * (float) $pricePerLiter, 2),
            ]));
        }
    }
}
