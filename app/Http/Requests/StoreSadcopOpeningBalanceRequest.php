<?php

namespace App\Http\Requests;

use App\Support\EntryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSadcopOpeningBalanceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', ...EntryRules::amount('0')],
            'occurred_at' => ['nullable', ...EntryRules::date($this->user())],
        ];
    }
}
