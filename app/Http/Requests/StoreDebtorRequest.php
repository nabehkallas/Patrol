<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDebtorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:debtors,name'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
