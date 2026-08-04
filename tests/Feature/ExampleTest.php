<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_cash_box_redirect_chain()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('cash-box.index'));
    }
}
