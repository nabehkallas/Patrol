<?php

namespace App\Http\Requests\Admin;

use App\Support\FuelColors;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFuelTypeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fuelType = $this->route('fuel_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', Rule::in(FuelColors::PALETTE)],
            'slug' => ['required', 'string', 'max:255', Rule::unique('fuel_types', 'slug')->ignore($fuelType)],
        ];
    }
}
