<?php

namespace App\Http\Requests;

use App\Enums\SadcopLedgerEntryType;
use App\Models\SadcopLedgerEntry;
use App\Models\Tank;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSadcopEntryRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var SadcopLedgerEntry $entry */
        $entry = $this->route('entry');

        return match ($entry->type) {
            SadcopLedgerEntryType::Opening => [
                'amount' => ['required', 'numeric', 'min:1'],
                'notes' => ['nullable', 'string'],
            ],
            SadcopLedgerEntryType::Deposit => [
                'amount' => ['required', 'numeric', 'min:1'],
                'occurred_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ],
            SadcopLedgerEntryType::Delivery => [
                'tank_id' => ['required', 'exists:tanks,id'],
                'liters' => ['required', 'numeric', 'min:0.001'],
                'price_per_liter' => ['required', 'numeric', 'min:0'],
                'amount' => ['required', 'numeric', 'min:1'],
                'occurred_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ],
        };
    }

    public function withValidator(Validator $validator): void
    {
        /** @var SadcopLedgerEntry $entry */
        $entry = $this->route('entry');

        if ($entry->type !== SadcopLedgerEntryType::Delivery) {
            return;
        }

        $validator->after(function (Validator $validator) use ($entry) {
            $tank = Tank::find($this->input('tank_id'));
            $liters = (float) $this->input('liters');

            if (! $tank || $liters <= 0) {
                return;
            }

            $remaining = $tank->remainingCapacity();

            // The entry's own delivery already reduced this tank's remaining capacity, so add
            // its current liters back before checking the new amount against the true ceiling.
            if ($entry->transaction && (int) $entry->transaction->tank_id === $tank->id) {
                $remaining += (float) $entry->transaction->liters;
            }

            if ($liters > $remaining + 0.001) {
                $validator->errors()->add('liters', __('This exceeds the tank\'s remaining capacity (:remaining L).', [
                    'remaining' => round($remaining, 1),
                ]));
            }
        });
    }
}
