<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetEarningsPasswordRequest;
use App\Models\EarningsPassword;
use App\Models\EarningsPasswordResetToken;
use App\Models\User;
use App\Notifications\EarningsPasswordResetNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class EarningsPasswordResetController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/earnings/forgot-password');
    }

    public function send(): RedirectResponse
    {
        $plainToken = EarningsPasswordResetToken::generate();

        $url = URL::to('/admin/earnings/reset-password/'.$plainToken);

        $admins = User::role(UserRole::Admin->value)->get();

        Notification::send($admins, new EarningsPasswordResetNotification($url));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('A reset link has been emailed to admins.'),
        ]);

        return to_route('admin.earnings.forgot-password');
    }

    public function edit(string $token): Response
    {
        return Inertia::render('admin/earnings/reset-password', [
            'token' => $token,
            'valid' => EarningsPasswordResetToken::verify($token),
        ]);
    }

    public function update(ResetEarningsPasswordRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (! EarningsPasswordResetToken::verify($data['token'])) {
            return back()->withErrors(['token' => __('This reset link is invalid or has expired.')]);
        }

        EarningsPassword::set($data['password']);
        EarningsPasswordResetToken::invalidateAll();

        session(['earnings_unlocked' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Earnings password reset.')]);

        return to_route('admin.earnings.index');
    }
}
