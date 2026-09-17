<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\TenantUserDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user),
                // Excludes this user's own current directory row (matched by their existing
                // email, which is about to be updated) -- otherwise re-saving a user without
                // changing their email would trip over their own directory entry.
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if ($value !== $user->email && TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    }
                },
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],
        ];
    }
}
