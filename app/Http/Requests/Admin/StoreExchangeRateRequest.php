<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', new Enum(Currency::class), 'in:SYP,TRY'],
            'rate_to_usd' => ['required', 'numeric', 'min:0.000001'],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
