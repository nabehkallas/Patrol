<?php

namespace App\Http\Requests;

use App\Models\Tank;
use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopDeliveryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tank_id' => ['required', 'exists:tanks,id'],
            'liters' => ['required', 'numeric', 'min:0.001'],
            'price_per_liter' => ['required', ...EntryRules::amount('0')],
            'amount' => ['required', ...EntryRules::amount('1')],
            'occurred_at' => ['nullable', ...EntryRules::date($this->user())],
            'notes' => EntryRules::notes(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            EntryRules::checkLitersTimesPrice($validator, $this->input('liters'), $this->input('price_per_liter'), $this->input('amount'));

            $tank = Tank::find($this->integer('tank_id'));
            $liters = (float) $this->input('liters');

            if (! $tank || $liters <= 0) {
                return;
            }

            $remaining = $tank->remainingCapacity();

            if ($liters > $remaining + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the tank\'s remaining capacity (:remaining L).', [
                    'remaining' => round($remaining, 1),
                ]));
            }
        });
    }
}
