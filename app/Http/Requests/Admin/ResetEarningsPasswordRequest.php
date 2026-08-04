<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ResetEarningsPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ];
    }
}
