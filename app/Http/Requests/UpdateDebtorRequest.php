<?php

namespace App\Http\Requests;

use App\Models\Debtor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDebtorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('debtors', 'name')->ignore($this->route('debtor'))],
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

            /** @var Debtor $debtor */
            $debtor = $this->route('debtor');

            if ((int) $parentId === $debtor->id) {
                $validator->errors()->add('parent_id', __('A debtor cannot be its own parent.'));

                return;
            }

            if ($debtor->children()->exists()) {
                $validator->errors()->add('parent_id', __('This debtor has sub-debtors and cannot itself become a sub-debtor.'));

                return;
            }

            $parent = Debtor::find($parentId);

            if ($parent && $parent->parent_id !== null) {
                $validator->errors()->add('parent_id', __('The selected debtor is itself a sub-debtor and cannot have sub-debtors of its own.'));
            }
        });
    }
}
