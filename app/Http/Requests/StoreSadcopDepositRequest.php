<?php

namespace App\Http\Requests;

use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopDepositRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', ...EntryRules::amount('1')],
            'occurred_at' => ['nullable', ...EntryRules::date($this->user())],
            'notes' => EntryRules::notes(),
        ];
    }
}
