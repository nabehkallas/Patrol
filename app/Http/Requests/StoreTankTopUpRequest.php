<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTankTopUpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tank_id' => ['required', 'exists:tanks,id'],
            'liters' => ['required', 'numeric', 'min:0.001'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
