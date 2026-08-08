<?php

namespace App\Http\Requests;

use App\Models\Tank;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTankTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from_tank_id' => ['required', 'exists:tanks,id', 'different:to_tank_id'],
            'to_tank_id' => ['required', 'exists:tanks,id'],
            'liters' => ['required', 'numeric', 'min:0.001'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fromTank = Tank::find($this->input('from_tank_id'));
            $toTank = Tank::find($this->input('to_tank_id'));

            if (! $fromTank || ! $toTank) {
                return;
            }

            if ($fromTank->fuel_type_id !== $toTank->fuel_type_id) {
                $validator->errors()->add('to_tank_id', __('Fuel can only be transferred between tanks of the same fuel type.'));

                return;
            }

            $liters = (float) $this->input('liters');

            if ($liters <= 0) {
                return;
            }

            $available = $fromTank->expectedLiters();

            if ($liters > $available + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the source tank\'s current amount (:available L).', [
                    'available' => round($available, 1),
                ]));
            }

            $remainingCapacity = $toTank->remainingCapacity();

            if ($liters > $remainingCapacity + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the destination tank\'s remaining capacity (:remaining L).', [
                    'remaining' => round($remainingCapacity, 1),
                ]));
            }
        });
    }
}
