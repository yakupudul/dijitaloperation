<?php

namespace Tests\Feature\Integrations;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Integrations\WordPressSitesPage;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/**
 * Faz 9c / ADR-068: WordPress Connector v2 — health, one-click login (admin, audited) and approved updates.
 */
final class WordPressManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private DigitalAsset $site;

    private CoreConnection $connection;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $sent = [];

    private bool $loginEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'Atlas Site', 'domain' => 'example.com']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.4.0'],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        Http::fake(function (Request $request) use ($secret) {
            $this->sent[] = [$request->method(), $request->url(), json_decode($request->body(), true)];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            [$status, $data] = match (true) {
                str_ends_with($request->url(), '/health') => [200, [
                    'plugin_version' => '1.3.0', 'wordpress_version' => '6.5.2', 'php_version' => '8.2.1', 'core_update' => '6.6.1',
                    'plugins' => [['file' => 'seo/seo.php', 'name' => 'SEO Eklentisi', 'version' => '2.0', 'active' => true, 'update' => '2.1'], ['file' => 'x/x.php', 'name' => 'X', 'version' => '1.0', 'active' => false, 'update' => null]],
                    'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4.0', 'active' => true, 'update' => null]],
                    'site_health' => ['good' => 15, 'recommended' => 4, 'critical' => 1], 'login_enabled' => true, 'updates_enabled' => true,
                ]],
                str_ends_with($request->url(), '/login-link') => $this->loginEnabled
                    ? [201, ['url' => 'https://example.com/?moxdop_login=abc', 'expires_in' => 60, 'user' => 'ajans']]
                    : [403, ['code' => 'moxdop_login_disabled']],
                default => [200, ['ok' => true, 'type' => 'plugin', 'item' => 'seo/seo.php', 'from_version' => '2.0', 'to_version' => '2.1', 'message' => 'Updated']],
            };
            if ($status >= 400) {
                return Http::response($data, $status);
            }
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]], $status);
        });
    }

    public function test_health_is_read_and_stored(): void
    {
        $this->assertSame(['checked' => 1, 'failed' => 0], app(WordPressManagementService::class)->refreshAll());
        $row = DB::table('wordpress_site_health')->where('digital_asset_id', $this->site->id)->first();
        $this->assertSame([2, 1], [(int) $row->pending_updates, (int) $row->critical_issues], 'core + one plugin');
        $this->assertSame('GET', $this->sent[0][0]);
        $this->assertSame('https://example.com/wp-json/moxdop/v1/health', $this->sent[0][1]);

        $this->connection->forceFill(['config' => array_merge($this->connection->config, ['plugin_version' => '1.2.0'])])->save();
        $this->assertSame(['checked' => 0, 'failed' => 0], app(WordPressManagementService::class)->refreshAll(), 'older plugins are skipped');
    }

    public function test_one_click_login_is_admin_only_and_audited(): void
    {
        app(WordPressManagementService::class)->refreshHealth($this->site);
        $this->actingAs($this->member);
        $this->post(route('operator.integrations.wordpress-login', ['site' => $this->site->id]))->assertForbidden();

        $this->actingAs($this->admin);
        $this->post(route('operator.integrations.wordpress-login', ['site' => $this->site->id]))->assertRedirect('https://example.com/?moxdop_login=abc');
        $this->assertSame(1, DB::table('security_audit_events')->where('kind', 'WORDPRESS_ADMIN_LOGIN')->count());

        $this->loginEnabled = false;
        $this->post(route('operator.integrations.wordpress-login', ['site' => $this->site->id]))->assertRedirect(route('operator.integrations.wordpress-sites'))
            ->assertSessionHas('wp_error');
        Livewire::test(WordPressSitesPage::class)->assertSee('Atlas Site')->assertSee('WP paneline gir')->assertSee('2 bekleyen güncelleme');
    }

    public function test_approved_update_is_admin_only_queued_recorded_and_not_undoable(): void
    {
        app(WordPressManagementService::class)->refreshHealth($this->site);
        $this->actingAs($this->member);
        Livewire::test(WordPressSitesPage::class, ['open' => $this->site->id])->assertDontSee('>Güncelle<', false)
            ->call('applyUpdate', $this->site->id, 'plugin', 'seo/seo.php', 'SEO Eklentisi')->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test(WordPressSitesPage::class, ['open' => $this->site->id])->assertSee('SEO Eklentisi')->assertSee('2.0 → 2.1')
            ->call('applyUpdate', $this->site->id, 'plugin', 'seo/seo.php', 'SEO Eklentisi')->assertSee('kuyruğa alındı');
        $action = ExternalWriteAction::query()->firstOrFail();
        $this->assertSame([ExternalWriteAction::ACTION_UPDATE_APPLY, 'succeeded', '2.1'], [$action->action, $action->status, $action->result['to_version']], (string) $action->error);
        $this->assertFalse($action->isUndoable());
        $update = collect($this->sent)->first(fn (array $s): bool => str_ends_with($s[1], '/updates'));
        $this->assertSame(['POST', ['type' => 'plugin', 'item' => 'seo/seo.php']], [$update[0], $update[2]]);
        $this->assertSame(2, collect($this->sent)->filter(fn (array $s): bool => str_ends_with($s[1], '/health'))->count(), 'health re-read after the update');

        config(['moxdop-external-writes.wordpress.enabled' => false]);
        Livewire::test(WordPressSitesPage::class)->call('applyUpdate', $this->site->id, 'core', '', 'WordPress')->assertSee('Harici yazma kapalı');
    }

    public function test_plugin_keeps_login_and_updates_off_by_default(): void
    {
        $management = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-management.php'));
        $this->assertStringContainsString("get_option('moxdop_connector_allow_updates', '0') === '1'", $management);
        $this->assertStringContainsString("get_option('moxdop_connector_login_user', 0)", $management);
        $this->assertStringContainsString('const LOGIN_TTL = 60;', $management);
        $this->assertStringContainsString('delete_transient($key);', $management, 'login links are single-use');
        $this->assertStringContainsString("define('MOXDOP_CONNECTOR_VERSION', '1.4.0')", file_get_contents(base_path('connectors/wordpress/moxdop-connector/moxdop-connector.php')));
    }
}
