<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Support\Integrations\Meta\MetaOAuthRedirectUriResolver;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicCanonicalUrlArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_named_operator_routes_live_at_the_site_root(): void
    {
        $this->assertSame('/', parse_url(route('operator.dashboard'), PHP_URL_PATH) ?: '/');
        $this->assertSame('/login', parse_url(route('app.login'), PHP_URL_PATH));
        $this->assertSame('/customers', parse_url(route('operator.customers'), PHP_URL_PATH));
        $this->assertSame('/brands', parse_url(route('operator.brands'), PHP_URL_PATH));
        $this->assertSame('/assets', parse_url(route('operator.assets'), PHP_URL_PATH));
        $this->assertSame('/integrations', parse_url(route('operator.integrations'), PHP_URL_PATH));
        $this->assertSame('/tasks', parse_url(route('operator.tasks'), PHP_URL_PATH));
        $this->assertSame('/settings', parse_url(route('operator.settings'), PHP_URL_PATH));
        $this->get('/admin/login')->assertOk();
    }

    public function test_google_and_meta_callbacks_stay_on_the_https_ready_domain_path(): void
    {
        config(['app.url' => 'https://app.moximu.com']);
        URL::forceRootUrl('https://app.moximu.com');
        URL::forceScheme('https');

        $this->assertSame(
            'https://app.moximu.com/integrations/google/callback',
            app(GoogleOAuthRedirectUriResolver::class)->canonicalFromAppUrl(),
        );
        $this->assertSame(
            'https://app.moximu.com/integrations/meta/callback',
            app(MetaOAuthRedirectUriResolver::class)->canonicalFromAppUrl(),
        );
        $this->assertSame(
            '/integrations/google/callback',
            parse_url(route('integrations.google.callback'), PHP_URL_PATH),
        );
        $this->assertSame(
            '/integrations/meta/callback',
            parse_url(route('integrations.meta.callback'), PHP_URL_PATH),
        );
    }

    public function test_authenticated_operator_surfaces_are_at_the_root_namespace(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $this->get('/')->assertOk()->assertSee(__('operator.dashboard_exec.needs_attention'));
        $this->get('/customers')->assertOk();
        $this->get('/brands')->assertOk();
        $this->get('/assets')->assertOk();
        $this->get('/integrations')->assertOk();
        $this->get('/tasks')->assertOk();
        $this->get('/settings')->assertOk();
        $this->get('/login')->assertRedirect('/');
    }

    public function test_legacy_app_and_system_namespaces_are_gone(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        foreach ([
            '/app',
            '/app/',
            '/app/login',
            '/app/customers',
            '/app/brands',
            '/app/assets',
            '/app/settings',
            '/system',
            '/system/',
            '/system/login',
            '/system/customers',
        ] as $path) {
            $this->get($path)
                ->assertStatus(410)
                ->assertDontSee('id="operator-sidebar"', false)
                ->assertDontSee(__('operator.auth.sign_in'));
        }
    }

    public function test_guest_legacy_namespaces_do_not_render_login_or_redirect_into_the_product(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/customers')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('MoxDOP');

        $this->get('/app/login')->assertStatus(410);
        $this->get('/app/customers')->assertStatus(410);
        $this->get('/system/login')->assertStatus(410);
    }

    public function test_filament_technical_panel_is_admin_only_and_not_a_legacy_prefix(): void
    {
        $panel = Filament::getPanel('app');

        $this->assertSame('app', $panel->getId());
        $this->assertSame('admin', $panel->getPath());
        $this->assertSame('/', $panel->getHomeUrl());

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->post('/app/login')->assertStatus(410);
        $this->post('/system/login')->assertStatus(410);
    }
}
