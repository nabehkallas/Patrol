<?php

namespace App\Http\Requests\Admin;

use App\Rules\UniqueTankName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTankRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fuel_type_id' => ['required', 'exists:fuel_types,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                new UniqueTankName($this->input('fuel_type_id')),
            ],
            'capacity_liters' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
