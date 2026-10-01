<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\EarningsPassword;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Gate for the annual financial summary tab on Statistics. It opens with either the station's
 * configurable PIN (the same protected-area password the Earnings page uses, set by an admin)
 * or the login password of any of the station's admins. Unlocking lasts for the session, the
 * same way the Earnings page does, until the user locks it again or logs out.
 */
class AnnualSummaryAccessController extends Controller
{
    public const SESSION_KEY = 'annual_summary_unlocked';

    public function unlock(Request $request): RedirectResponse
    {
        $password = $request->validate(['password' => ['required', 'string']])['password'];

        $matchesAdmin = User::role(UserRole::Admin->value)
            ->get(['id', 'password'])
            ->contains(fn (User $admin) => Hash::check($password, $admin->password));

        if (! $matchesAdmin && ! EarningsPassword::check($password)) {
            return back()->withErrors(['password' => __('Incorrect password.')]);
        }

        $request->session()->put(self::SESSION_KEY, true);

        return to_route('statistics.index', ['tab' => 'annual', 'year' => $request->integer('year') ?: null]);
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return to_route('statistics.index');
    }
}
