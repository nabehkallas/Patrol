<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\SanitizeDateFilters;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Support\ClientIp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

/** The hardening around "forgot password" / "reset password" and the global request rules. */
class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'If this email is registered, password reset instructions have been sent.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    /** Requests a link for $user and returns the plain token from the emailed notification. */
    private function requestToken(User $user): string
    {
        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return (string) $token;
    }

    public function test_forgot_password_answers_the_same_for_unknown_and_known_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->from('/forgot-password')->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post(route('password.email'), ['email' => 'nobody@example.com']);
        // A second request for the real account inside the 60-second cooldown is throttled by the
        // broker -- and must not reveal that either.
        $again = $this->from('/forgot-password')->post(route('password.email'), ['email' => $user->email]);

        foreach ([$known, $unknown, $again] as $response) {
            $response->assertSessionHasNoErrors()->assertSessionHas('status', self::GENERIC);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_the_reset_token_is_stored_hashed_and_expires_after_five_minutes(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $stored = (string) \DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
        $this->assertGreaterThanOrEqual(64, strlen($token));

        $this->travel(6)->minutes();

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'new-Password-1', 'password_confirmation' => 'new-Password-1',
        ])->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('new-Password-1', $user->fresh()->password));
    }

    public function test_five_wrong_tokens_kill_the_outstanding_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.update'), [
                'token' => str_repeat('x', 64), 'email' => $user->email, 'password' => 'new-Password-1', 'password_confirmation' => 'new-Password-1',
            ])->assertSessionHasErrors('email');
        }

        // The genuine token no longer works either.
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'new-Password-1', 'password_confirmation' => 'new-Password-1',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_a_successful_reset_is_single_use_rotates_remember_token_and_sends_a_notice(): void
    {
        Notification::fake();
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $token = $this->requestToken($user);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'new-Password-1', 'password_confirmation' => 'new-Password-1'];

        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-Password-1', $fresh->password));
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        Notification::assertSentTo($user, PasswordChanged::class);

        // Replaying the same token fails: it was deleted on use.
        $this->post(route('password.update'), [...$payload, 'password' => 'other-Password-2', 'password_confirmation' => 'other-Password-2'])
            ->assertSessionHasErrors('email');
    }

    public function test_link_requests_are_limited_per_ip(): void
    {
        Notification::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => "someone{$i}@example.com"])->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'someone9@example.com'])->assertSessionHasErrors('email');
    }

    public function test_signed_in_areas_sign_out_sessions_after_a_password_change(): void
    {
        foreach (['cash-box.index', 'platform.home', 'profile.edit'] as $name) {
            $this->assertContains('auth.session', Route::getRoutes()->getByName($name)->gatherMiddleware(), "{$name} lacks auth.session");
        }
    }

    public function test_every_page_has_a_request_budget(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->get('/login')->assertOk();
        }

        $this->get('/login')->assertStatus(429);
    }

    public function test_malformed_date_filters_are_dropped(): void
    {
        $request = Request::create('/cash-box', 'GET', ['from' => "2026-02-30' OR 1=1", 'to' => '2026-10-05', 'date' => 'tomorrow']);

        (new SanitizeDateFilters)->handle($request, fn () => response('ok'));

        $this->assertFalse($request->query->has('from'));
        $this->assertSame('2026-10-05', $request->query('to'));
        $this->assertFalse($request->query->has('date'));
    }

    public function test_rate_limits_use_the_ip_fly_reports_not_a_forgeable_header(): void
    {
        $request = Request::create('/login', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_FLY_CLIENT_IP' => '203.0.113.7']);

        $this->assertSame('203.0.113.7', ClientIp::of($request));
    }
}
