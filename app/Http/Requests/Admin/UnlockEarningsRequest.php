<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UnlockEarningsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
