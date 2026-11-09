<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
    }
}
