<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFuelTypeProfitMarginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profit_margin_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
