<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * "Forgot password" answers the same way whether or not the email has an account: Fortify would
 * otherwise reply "no user with that email" (or "please wait" -- which only happens for real
 * accounts), letting anyone probe which addresses are registered.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
