<?php

namespace App\Http\Requests\Concerns;

use App\Models\FuelType;
use App\Support\EntryRules;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Throwable;

/**
 * A fuel debt (one with a fuel type) is in its fuel price's currency, whatever the form sent,
 * and its amount must be liters × price per liter.
 */
trait ValidatesFuelDebt
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('fuel_type_id')) {
            return;
        }

        $fuelType = FuelType::find($this->integer('fuel_type_id'));

        try {
            $date = $this->filled('date') ? Carbon::parse((string) $this->input('date'))->endOfDay() : now();
        } catch (Throwable) {
            $date = now();
        }

        $price = $fuelType?->priceAt($date) ?? $fuelType?->currentPrice();

        if ($price !== null) {
            $this->merge(['currency' => $price->currency]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('fuel_type_id')) {
                EntryRules::checkLitersTimesPrice($validator, $this->input('liters'), $this->input('price_per_liter'), $this->input('amount'));
            }
        });
    }
}
