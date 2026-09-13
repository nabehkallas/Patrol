<?php

namespace App\Http\Requests;

use App\Models\Tank;
use App\Models\TankTransfer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTankTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'liters' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var TankTransfer $transfer */
            $transfer = $this->route('transfer');
            $liters = (float) $this->input('liters');

            if ($liters <= 0) {
                return;
            }

            $fromTank = Tank::withTrashed()->find($transfer->from_tank_id);
            $toTank = Tank::withTrashed()->find($transfer->to_tank_id);

            if (! $fromTank || ! $toTank) {
                return;
            }

            // This transfer's own current liters are already counted in both tanks' derived
            // balances (as a transferOut of $fromTank, a transferIn of $toTank) -- add them
            // back before checking against the new value, the same way editing an existing
            // delivery adds its own liters back to the tank's ceiling (see
            // TransactionEdit.tsx's maxLiters).
            $available = $fromTank->expectedLiters() + (float) $transfer->liters;

            if ($liters > $available + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the source tank\'s current amount (:available L).', [
                    'available' => round($available, 1),
                ]));
            }

            $remainingCapacity = $toTank->remainingCapacity() + (float) $transfer->liters;

            if ($liters > $remainingCapacity + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the destination tank\'s remaining capacity (:remaining L).', [
                    'remaining' => round($remainingCapacity, 1),
                ]));
            }
        });
    }
}
