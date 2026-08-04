<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tank_id' => ['required', 'exists:tanks,id'],
            'date' => ['required', 'date'],
            'quantity_liters' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
