<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A station account's email is its sign-in name across the platform (the central
        // directory): only its station admin changes it, on the Employees page. Not validated
        // here, so it never reaches the update.
        if (tenancy()->initialized) {
            return ['name' => $this->nameRules()];
        }

        return $this->profileRules($this->user()->id);
    }
}
