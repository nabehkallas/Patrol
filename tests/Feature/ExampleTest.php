<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_public_landing_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('welcome'));
    }

    public function test_signed_in_platform_admins_go_to_their_panel_instead(): void
    {
        // A central (platform admin) user: no station tenancy is active.
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect('/platform');
    }
}
