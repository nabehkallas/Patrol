<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The platform (super) admin's account dialog: profile/email and password changes. */
class PlatformAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_changed_with_the_current_password(): void
    {
        $admin = User::factory()->create(['password' => 'Old-Password-123']);

        $this->actingAs($admin)
            ->from('/platform')
            ->put(route('platform.account.password'), [
                'current_password' => 'Old-Password-123',
                'password' => 'New-Password-456',
                'password_confirmation' => 'New-Password-456',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/platform');

        $this->assertTrue(Hash::check('New-Password-456', $admin->fresh()->password));
    }

    public function test_password_change_is_refused_with_a_wrong_current_password(): void
    {
        $admin = User::factory()->create(['password' => 'Old-Password-123']);

        $this->actingAs($admin)
            ->from('/platform')
            ->put(route('platform.account.password'), [
                'current_password' => 'not-it',
                'password' => 'New-Password-456',
                'password_confirmation' => 'New-Password-456',
            ])
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('Old-Password-123', $admin->fresh()->password));
    }

    public function test_changing_email_requires_confirming_the_new_address(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['password' => 'Old-Password-123']);

        $this->actingAs($admin)
            ->from('/platform')
            ->patch(route('platform.account.update'), [
                'name' => 'Renamed Admin',
                'email' => 'new-admin@example.com',
                'current_password' => 'Old-Password-123',
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertSame('Renamed Admin', $admin->name);
        $this->assertSame('new-admin@example.com', $admin->email);
        $this->assertNull($admin->email_verified_at);
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_email_already_used_by_another_admin_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $admin = User::factory()->create(['password' => 'Old-Password-123']);

        $this->actingAs($admin)
            ->from('/platform')
            ->patch(route('platform.account.update'), [
                'name' => $admin->name,
                'email' => 'taken@example.com',
                'current_password' => 'Old-Password-123',
            ])
            ->assertSessionHasErrorsIn('profile', 'email');
    }
}
