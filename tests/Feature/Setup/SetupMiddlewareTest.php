<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function webPaths(): array
    {
        return [
            'dashboard' => ['/'],
            'login' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'security settings' => ['/settings/security'],
            'two factor' => ['/two-factor-challenge'],
        ];
    }

    #[DataProvider('webPaths')]
    public function test_with_no_users_every_web_route_redirects_to_setup(string $path): void
    {
        $this->get($path)->assertRedirect(route('setup'));
    }

    public function test_setup_health_and_dev_routes_stay_reachable_on_a_fresh_install(): void
    {
        $this->get('/setup')->assertOk()->assertSee('Create the administrator');
        $this->get('/up')->assertOk();

        $this->app['env'] = 'local';
        $this->get('/_ui')->assertOk();
    }

    public function test_once_a_user_exists_setup_is_closed(): void
    {
        $user = User::factory()->create();

        $this->get('/setup')->assertRedirect(route('overview'));
        $this->get('/login')->assertOk();
        $this->actingAs($user)->get('/setup')->assertRedirect(route('overview'));
    }

    public function test_a_soft_deleted_user_still_counts_as_set_up(): void
    {
        User::factory()->create()->delete();

        $this->get('/setup')->assertRedirect(route('overview'));
        $this->get('/login')->assertOk();
    }
}
