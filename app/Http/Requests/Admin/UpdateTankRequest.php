<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTankRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fuel_type_id' => ['required', 'exists:fuel_types,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tanks')->where('fuel_type_id', $this->input('fuel_type_id'))->ignore($this->route('tank')),
            ],
            'capacity_liters' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
