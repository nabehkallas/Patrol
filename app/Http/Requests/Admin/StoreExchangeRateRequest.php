<?php

namespace App\Http\Requests\Admin;

use App\Support\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Any of the station's currencies except USD, the pivot every rate is quoted against.
            'currency' => ['required', Currency::rule(), Rule::notIn([Currency::USD])],
            'rate_to_usd' => ['required', 'numeric', 'min:0.000001'],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
