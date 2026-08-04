<?php

namespace App\Http\Requests;

use App\Models\Tank;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopDeliveryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tank_id' => ['required', 'exists:tanks,id'],
            'liters' => ['required', 'numeric', 'min:0.001'],
            'price_per_liter' => ['required', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:1'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tank = Tank::find($this->input('tank_id'));
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
