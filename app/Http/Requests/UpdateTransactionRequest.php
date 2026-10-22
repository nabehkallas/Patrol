<?php

namespace App\Http\Requests;

use App\Enums\DebtDirection;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\Tank;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateTransactionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Fuel sales are recorded by pump counters only (each one is a counter reading), so
            // they can't be entered here as well and counted twice.
            'type' => ['required', Rule::enum(TransactionType::class)->except([TransactionType::FuelSale])],
            'tank_id' => ['required_if:type,fuel_delivery', 'nullable', 'exists:tanks,id'],
            'liters' => ['required_if:type,fuel_delivery', 'nullable', 'numeric', 'min:0.001'],
            'price_per_liter' => ['required_if:type,fuel_delivery', 'nullable', ...EntryRules::amount('0')],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', ...EntryRules::amount()],
            'currency' => ['required', Currency::rule()],
            'to_currency' => ['nullable', 'required_if:type,currency_exchange', Currency::rule()],
            'to_amount' => ['nullable', 'required_if:type,currency_exchange', ...EntryRules::amount()],
            // The rate of a currency exchange (required there, used nowhere else).
            'exchange_rate_to_usd' => ['nullable', 'required_if:type,currency_exchange', 'numeric', 'gt:0', 'max:'.EntryRules::MAX_AMOUNT],
            'occurred_at' => ['nullable', ...EntryRules::date($this->user())],
            'notes' => EntryRules::notes(),
            'other_income_category' => ['nullable', 'required_if:type,other_income', new Enum(OtherIncomeCategory::class)],
            'mark_as_debt' => ['nullable', 'boolean'],
            'debt_debtor_id' => ['nullable', 'required_if:mark_as_debt,1', 'exists:debtors,id'],
            'debt_direction' => ['nullable', new Enum(DebtDirection::class)],
            // Where money paid out in another currency comes from (see CashBox).
            'pay_from' => ['nullable', 'in:own,primary'],
            'cash_rate' => ['nullable', 'numeric', 'gt:0', 'max:'.EntryRules::MAX_AMOUNT],
            'confirm_negative_cash' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') === TransactionType::CurrencyExchange->value
                && $this->input('currency') === $this->input('to_currency')) {
                $validator->errors()->add('to_currency', __('Choose a different currency to exchange into.'));
            }

            if ($this->input('type') !== TransactionType::FuelDelivery->value) {
                return;
            }

            EntryRules::checkLitersTimesPrice($validator, $this->input('liters'), $this->input('price_per_liter'), $this->input('amount'));

            $tank = Tank::find($this->integer('tank_id'));
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
