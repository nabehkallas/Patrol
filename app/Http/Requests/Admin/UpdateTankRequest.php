<?php

namespace App\Http\Requests\Admin;

use App\Models\Tank;
use App\Rules\UniqueTankName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTankRequest extends FormRequest
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
                new UniqueTankName($this->input('fuel_type_id'), $this->tankId()),
            ],
            'capacity_liters' => ['required', 'numeric', 'min:0.001'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** The tank being edited (the route binds the model). */
    private function tankId(): ?int
    {
        $tank = $this->route('tank');

        return $tank instanceof Tank ? $tank->id : null;
    }
}
