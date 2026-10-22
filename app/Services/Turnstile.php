<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile, the captcha on the public sign-up form. Off until both keys are set
 * (TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY); once on, a request without a valid token -- or
 * one Cloudflare can't be asked about -- is refused.
 */
class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return $this->enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public function passes(?string $token, string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return $response->ok() && $response->json('success') === true;
        } catch (Throwable $e) {
            Log::warning('Turnstile verification failed: '.$e->getMessage());

            return false;
        }
    }
}
