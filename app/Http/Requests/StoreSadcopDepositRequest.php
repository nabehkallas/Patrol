<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopDepositRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
