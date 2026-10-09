<?php

namespace App\Rules;

use App\Models\Tank;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A fuel type can't have two tanks with the same name, so the tank dropdowns (which show only the
 * tank name, grouped by fuel type) are never ambiguous. Compared without regard to letter case
 * or surrounding spaces; deleted tanks still count, as the database index includes them.
 */
class UniqueTankName implements ValidationRule
{
    public function __construct(
        private readonly mixed $fuelTypeId,
        private readonly ?int $ignoreTankId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! is_numeric($this->fuelTypeId)) {
            return;
        }

        $taken = Tank::withTrashed()
            ->where('fuel_type_id', (int) $this->fuelTypeId)
            ->when($this->ignoreTankId, fn ($query) => $query->whereKeyNot($this->ignoreTankId))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($value))])
            ->exists();

        if ($taken) {
            $fail(__('tanks.name_taken'));
        }
    }
}
