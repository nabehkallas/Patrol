<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDebtorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('debtors', 'name')->ignore($this->route('debtor'))],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
