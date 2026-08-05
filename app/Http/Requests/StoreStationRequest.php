<?php

namespace App\Http\Requests;

use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreStationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'station_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => [
                'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::where('email', $value)->exists() || TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    }
                },
            ],
        ];
    }
}
