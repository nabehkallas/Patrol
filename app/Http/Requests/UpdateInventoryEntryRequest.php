<?php

namespace App\Http\Requests;

use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryEntryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tank_id' => ['required', 'exists:tanks,id'],
            'date' => ['required', ...EntryRules::date($this->user())],
            'quantity_liters' => ['required', 'numeric', 'min:0'],
            'notes' => EntryRules::notes(),
        ];
    }
}
