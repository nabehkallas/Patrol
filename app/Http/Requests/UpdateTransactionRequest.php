<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Tank;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(TransactionType::class)],
            'tank_id' => ['required_if:type,fuel_sale,fuel_delivery', 'nullable', 'exists:tanks,id'],
            'liters' => ['required_if:type,fuel_sale,fuel_delivery', 'nullable', 'numeric', 'min:0.001'],
            'price_per_liter' => ['required_if:type,fuel_sale', 'nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', new Enum(Currency::class)],
            'exchange_rate_to_usd' => ['nullable', 'numeric', 'min:0.000001'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'mark_as_debt' => ['nullable', 'boolean'],
            'debt_debtor_id' => ['nullable', 'required_if:mark_as_debt,1', 'exists:debtors,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== TransactionType::FuelDelivery->value) {
                return;
            }

            $tank = Tank::find($this->input('tank_id'));
            $liters = (float) $this->input('liters');

            if (! $tank || $liters <= 0) {
                return;
            }

            $remaining = $tank->remainingCapacity();

            /** @var Transaction $transaction */
            $transaction = $this->route('transaction');

            if ($transaction->type === TransactionType::FuelDelivery && (int) $transaction->tank_id === $tank->id) {
                $remaining += (float) $transaction->liters;
            }

            if ($liters > $remaining + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the tank\'s remaining capacity (:remaining L).', [
                    'remaining' => round($remaining, 1),
                ]));
            }
        });
    }
}
