<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_ui_showcase_is_hidden_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->get('/_ui')->assertNotFound();
    }

    public function test_ui_showcase_renders_in_local_with_every_primitive(): void
    {
        $this->app['env'] = 'local';

        $this->get('/_ui')
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="switch"', false)
            ->assertSee('table-stack', false)
            ->assertSee('Skip to content');
    }

    public function test_overview_uses_the_app_layout(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="main"', false)
            ->assertSee('aria-label="Main navigation"', false)
            ->assertSee('Theme');
    }

    public function test_theme_is_applied_before_first_paint(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertSee("localStorage.getItem('theme')", false);
    }
}
