<?php

namespace App\Http\Requests;

use App\Models\Debtor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDebtorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:debtors,name'],
            'phone' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'exists:debtors,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_id');

            if (! $parentId) {
                return;
            }

            $parent = Debtor::find($parentId);

            if ($parent && $parent->parent_id !== null) {
                $validator->errors()->add('parent_id', __('The selected debtor is itself a sub-debtor and cannot have sub-debtors of its own.'));
            }
        });
    }
}
