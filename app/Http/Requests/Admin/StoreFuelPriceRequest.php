<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreFuelPriceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fuel_type_id' => ['required', 'exists:fuel_types,id'],
            'price_per_liter' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', new Enum(Currency::class)],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
