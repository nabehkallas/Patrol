<?php

namespace App\Http\Requests\Admin;

use App\Support\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFuelPriceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fuel_type_id' => ['required', 'exists:fuel_types,id'],
            'price_per_liter' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Currency::rule()],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
