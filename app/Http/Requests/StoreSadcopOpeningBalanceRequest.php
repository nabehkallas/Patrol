<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopOpeningBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
