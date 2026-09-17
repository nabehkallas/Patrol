<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\TenantUserDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email'),
                // tenant_user_directory.email is globally unique across every station -- without
                // this check, creating a user whose email another station already uses would fail
                // at the database level only after the tenant's own `users` row was already
                // committed, leaving a user who can never log in (see UserController::store()).
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],
        ];
    }
}
