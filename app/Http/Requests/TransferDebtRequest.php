<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferDebtRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'debtor_id' => ['required', 'exists:debtors,id'],
        ];
    }
}
