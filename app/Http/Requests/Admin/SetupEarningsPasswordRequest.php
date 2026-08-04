<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class SetupEarningsPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }
}
