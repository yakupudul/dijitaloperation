<?php

namespace Tests\Feature\PhaseF;

use App\Models\User;
use App\Support\Roles;
use App\Support\Work\WorkUrl;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhaseFReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_http_routes_can_be_cached_for_staging_deploy(): void
    {
        try {
            $this->artisan('route:cache')->assertSuccessful();
        } finally {
            Artisan::call('route:clear');
        }
    }

    public function test_canonical_root_and_admin_paths_remain_the_release_contract(): void
    {
        $this->assertSame('/login', parse_url(route('app.login'), PHP_URL_PATH));
        $this->assertSame('/', parse_url(route('operator.dashboard'), PHP_URL_PATH) ?: '/');
        $this->assertSame('/integrations/google/callback', parse_url(route('integrations.google.callback'), PHP_URL_PATH));
        $this->assertSame('/integrations/meta/callback', parse_url(route('integrations.meta.callback'), PHP_URL_PATH));

        $this->get('/login')->assertOk();
        $this->get('/admin/login')->assertOk();
        $this->get('/app/login')->assertStatus(410);
        $this->get('/system/login')->assertStatus(410);
        $this->get('/up/liveness')->assertOk()->assertJsonPath('status', 'HEALTHY');
        $this->get('/up/readiness')->assertOk()->assertJsonMissingPath('credentials');
    }

    public function test_force_https_generates_secure_operator_and_oauth_urls(): void
    {
        config([
            'app.url' => 'https://app.moximu.com',
            'app.force_https' => true,
        ]);
        URL::forceRootUrl('https://app.moximu.com');
        URL::forceScheme('https');

        $this->assertSame('https://app.moximu.com/login', route('app.login'));
        $this->assertSame(
            'https://app.moximu.com/integrations/google/callback',
            route('integrations.google.callback'),
        );
        $this->assertSame(
            'https://app.moximu.com/integrations/meta/callback',
            route('integrations.meta.callback'),
        );
    }

    public function test_readiness_and_liveness_do_not_leak_secrets_or_traces(): void
    {
        $liveness = $this->getJson('/up/liveness')->assertOk()->json();
        $readiness = $this->getJson('/up/readiness')->assertOk()->json();
        $encoded = json_encode([$liveness, $readiness]) ?: '';

        $this->assertStringNotContainsString((string) config('app.key'), $encoded);
        $this->assertDoesNotMatchRegularExpression('/password|secret|token|stack trace|sqlstate/i', $encoded);
        $this->assertArrayNotHasKey('exception', $readiness);
        $this->assertSame(['database', 'redis', 'storage'], array_keys($readiness['dependencies'] ?? []));
    }

    public function test_legacy_work_and_retired_asset_redirects_stay_controller_backed(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $this->get(route('operator.domain'))->assertRedirect(route('operator.assets'));
        $this->get(route('operator.hosting'))->assertRedirect(route('operator.assets'));
        $this->get('/work/1?type='.WorkUrl::TYPE_TASK)
            ->assertRedirect(route('operator.work.show', WorkUrl::parameters(WorkUrl::TYPE_TASK, '1')));
        $this->get('/work/1')->assertNotFound();
    }

    public function test_production_check_does_not_print_app_key_or_fail_closed_https_in_testing(): void
    {
        $exit = Artisan::call('moxdop:production-check', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertTrue(in_array($exit, [0, 1], true));
        $this->assertIsArray($payload);
        $this->assertStringNotContainsString((string) config('app.key'), $output);

        $https = collect($payload['checks'])->firstWhere('check', 'HTTPS');
        $this->assertIsArray($https);
        $this->assertSame('WARN', $https['result']);

        $oauth = collect($payload['checks'])->firstWhere('check', 'OAUTH_CALLBACKS');
        $this->assertIsArray($oauth);
        $this->assertSame('PASS', $oauth['result']);
    }
}
