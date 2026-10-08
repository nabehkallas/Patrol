<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The platform (super) admin's own account: name/email and password, edited from the
 * account dialog in the platform panel header. Both changes require the current password.
 */
class PlatformAccountController extends Controller
{
    use PasswordValidationRules;

    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                // Station accounts live in other databases; the login directory lists them all.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    }
                },
            ],
            'current_password' => $this->currentPasswordRules(),
        ]);

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendVerificationLink();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailChanged
            ? __('Account updated. A confirmation link was sent to your new email address.')
            : __('Account updated.')]);

        return back();
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        $request->user()->update(['password' => $data['password']]);
        $request->user()->notifyPasswordChanged();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
