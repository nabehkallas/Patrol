<?php

namespace Tests\Feature;

use App\Enums\StationStatus;
use App\Http\Controllers\StationRegistrationController;
use App\Models\PendingRegistration;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use App\Notifications\ConfirmStationRegistration;
use App\Services\StationProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Public sign-up: a captcha, then only a pending row and an email -- no station or database --
 * until the emailed link is opened. Open sign-ups are capped and expire after 48 hours.
 */
class StationSignUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutVite();
    }

    private function signUp(array $input = []): TestResponse
    {
        return $this->post(route('register.store'), [
            'station_name' => 'محطة النور',
            'owner_name' => 'Owner',
            'email' => 'owner@example.test',
            'phone' => '+963 944 000 000',
            'password' => 'Secret-Pass-2026!',
            'password_confirmation' => 'Secret-Pass-2026!',
            ...$input,
        ]);
    }

    private function pending(array $attributes = []): PendingRegistration
    {
        return PendingRegistration::create([
            'station_name' => 'Station', 'owner_name' => 'Owner', 'email' => Str::random(8).'@example.test',
            'phone' => '0944000000', 'password' => bcrypt('x'), 'token_hash' => hash('sha256', 'x'),
            'ip' => '10.0.0.1', 'expires_at' => now()->addHours(48), ...$attributes,
        ]);
    }

    public function test_signing_up_stores_only_a_pending_registration_and_emails_the_link(): void
    {
        $this->signUp()->assertRedirect(route('register.sent'));

        $registration = PendingRegistration::sole();
        $this->assertSame('owner@example.test', $registration->email);
        $this->assertTrue(Hash::check('Secret-Pass-2026!', $registration->password));
        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, TenantUserDirectory::count());
        $this->assertGuest();
        Notification::assertSentOnDemand(ConfirmStationRegistration::class);
    }

    public function test_the_captcha_is_required_once_configured(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(fn (HttpRequest $request) => Http::response(['success' => $request['response'] === 'good-token']));

        $this->signUp()->assertSessionHasErrors('captcha');
        $this->signUp(['cf-turnstile-response' => 'bad-token'])->assertSessionHasErrors('captcha');
        $this->assertSame(0, PendingRegistration::count());

        $this->signUp(['cf-turnstile-response' => 'good-token'])->assertRedirect(route('register.sent'));
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_open_sign_ups_are_capped_per_ip_and_overall(): void
    {
        foreach (range(1, StationRegistrationController::MAX_OPEN_PER_IP) as $i) {
            $this->pending(['ip' => '127.0.0.1']);
        }
        $this->signUp()->assertSessionHasErrors('email');

        PendingRegistration::query()->delete();
        foreach (range(1, StationRegistrationController::MAX_OPEN_TOTAL) as $i) {
            $this->pending(['ip' => "10.0.{$i}.1"]);
        }
        $this->signUp()->assertSessionHasErrors('email');

        // Expired ones don't count.
        PendingRegistration::query()->update(['expires_at' => now()->subMinute()]);
        $this->signUp()->assertRedirect(route('register.sent'));
    }

    public function test_an_open_sign_up_blocks_another_for_the_same_email(): void
    {
        $this->pending(['email' => 'owner@example.test']);

        $this->signUp()->assertSessionHasErrors('email');
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_opening_the_link_creates_the_station_awaiting_approval_and_signs_the_owner_in(): void
    {
        $this->signUp();
        $registration = PendingRegistration::sole();
        $token = 'T'.Str::random(47);
        $registration->update(['token_hash' => hash('sha256', $token)]);

        $this->mock(StationProvisioner::class, function ($mock) {
            $mock->shouldReceive('create')->once()
                ->withArgs(fn (string $station, string $owner, string $email, string $password, bool $mustChange, StationStatus $status) => $station === 'محطة النور' && $email === 'owner@example.test'
                    && Hash::check('Secret-Pass-2026!', $password) && ! $mustChange && $status === StationStatus::PendingApproval)
                ->andReturnUsing(function (string $station, string $owner, string $email, string $password) {
                    // The real one creates the station's database; here only its rows.
                    $tenant = Tenant::withoutEvents(fn () => Tenant::create(['id' => (string) Str::uuid(), 'name' => $station, 'status' => StationStatus::PendingApproval->value]));

                    return [$tenant, User::create(['name' => $owner, 'email' => $email, 'password' => $password])];
                });
        });

        $this->get(route('register.verify', ['registration' => $registration->id, 'token' => $token]))
            ->assertRedirect(route('registration.pending'));

        $owner = User::where('email', 'owner@example.test')->sole();
        $this->assertTrue(Hash::check('Secret-Pass-2026!', $owner->password)); // hashed once, not twice
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame(0, PendingRegistration::count());
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_wrong_or_expired_link_creates_nothing(): void
    {
        $token = 'T'.Str::random(47);
        $registration = $this->pending(['token_hash' => hash('sha256', $token)]);
        $this->mock(StationProvisioner::class, fn ($mock) => $mock->shouldNotReceive('create'));

        $this->get(route('register.verify', ['registration' => $registration->id, 'token' => 'W'.Str::random(47)]))->assertNotFound();

        $registration->update(['expires_at' => now()->subMinute()]);
        $this->get(route('register.verify', ['registration' => $registration->id, 'token' => $token]))->assertRedirect(route('register'));
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_unconfirmed_sign_ups_are_pruned_after_48_hours(): void
    {
        $this->pending(['expires_at' => now()->subMinute()]);
        $open = $this->pending();

        $this->artisan('model:prune', ['--model' => [PendingRegistration::class]])->assertSuccessful();

        $this->assertSame([$open->id], PendingRegistration::pluck('id')->all());
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
