<?php

namespace App\Http\Requests;

use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDebtPaymentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', ...EntryRules::amount()],
            // Where a payment the station makes comes from (see CashBox).
            'pay_from' => ['nullable', 'in:own,primary'],
            'cash_rate' => ['nullable', 'numeric', 'gt:0', 'max:'.EntryRules::MAX_AMOUNT],
            'confirm_negative_cash' => ['nullable', 'boolean'],
        ];
    }
}
