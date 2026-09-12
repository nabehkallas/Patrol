<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreferencesUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'default_entry_date' => ['required', Rule::in(['today', 'yesterday'])],
        ];
    }
}
