<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFuelTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $fuelType = $this->route('fuel_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('fuel_types', 'slug')->ignore($fuelType)],
        ];
    }
}
