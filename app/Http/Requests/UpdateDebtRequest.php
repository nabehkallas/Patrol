<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDebtRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'debtor_id' => ['required', 'exists:debtors,id'],
            'fuel_type_id' => ['nullable', 'exists:fuel_types,id'],
            'liters' => ['nullable', 'required_with:fuel_type_id', 'numeric', 'min:0.001'],
            'price_per_liter' => ['nullable', 'required_with:fuel_type_id', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', new Enum(Currency::class)],
            'exchange_rate_to_usd' => ['nullable', 'numeric', 'min:0.000001'],
            'date' => ['required', 'date'],
            'details' => ['nullable', 'string'],
            'status' => ['required', new Enum(DebtStatus::class)],
        ];
    }
}
