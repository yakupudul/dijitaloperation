<?php

namespace Tests\Feature\Mcp;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\BuildSite;
use App\Mcp\Tools\InspectSite;
use App\Mcp\Tools\ListSites;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/**
 * Connector 1.8.0: Claude builds a WordPress site over MCP (list-sites, inspect-site, build-site) only while an Admin
 * switched "Claude site kurulumu" on in MoxDOP and the plugin reports "Site building" (capability build).
 */
final class McpSiteBuildTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $site;

    private CoreConnection $connection;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $sent = [];

    /** @var list<string> */
    private array $statusCapabilities = ['drafts', 'build'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'Yeni Site', 'domain' => 'example.com']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'status_url' => 'https://example.com/wp-json/moxdop/v1/status', 'plugin_version' => '1.8.0', 'capabilities' => ['drafts', 'build']],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        Http::fake(function (Request $request) use ($secret) {
            $this->sent[] = [$request->method(), $request->url(), json_decode($request->body(), true)];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = match (true) {
                str_ends_with($request->url(), '/status') => ['plugin_version' => '1.8.0', 'capabilities' => $this->statusCapabilities],
                $request->method() === 'GET' => ['schema_version' => 1, 'acf' => ['active' => true, 'pro' => true], 'elementor' => ['active' => true, 'pro' => true], 'built' => []],
                default => ['schema_version' => 1, 'results' => array_map(fn (array $op): array => $op['op'] === 'menu'
                    ? ['op' => 'menu', 'ok' => false, 'errors' => ['theme has no menu location primary']]
                    : ['op' => $op['op'], 'ok' => true, 'ref' => $op['ref'] ?? null, 'id' => 10], json_decode($request->body(), true)['operations'])],
            };
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }

    public function test_nothing_is_sent_while_the_moxdop_switch_is_off(): void
    {
        MoxdopServer::tool(ListSites::class, [])->assertOk()->assertSee('Claude site kurulumu');
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'post', 'ref' => 'page-home', 'title' => 'Ana sayfa']]])
            ->assertHasErrors(['Claude site kurulumu']);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_an_admin_switches_it_on_and_claude_inspects_and_builds(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)
            ->call('toggleClaudeBuild')->assertSee('Açık · kapat');
        $this->assertTrue((bool) data_get($this->connection->fresh()->config, 'claude_build.enabled'));

        MoxdopServer::tool(ListSites::class, [])->assertOk()->assertSee('"ready":true', false);
        MoxdopServer::tool(InspectSite::class, ['site_id' => $this->site->id])->assertOk()->assertSee('elementor');
        $this->assertSame(['GET', 'https://example.com/wp-json/moxdop/v1/build'], array_slice($this->sent[0], 0, 2));

        $operations = [
            ['op' => 'media', 'ref' => 'img-logo', 'data_base64' => base64_encode('png-bytes'), 'filename' => 'logo.png', 'alt' => 'Logo'],
            ['op' => 'post', 'ref' => 'page-hizmetler', 'post_type' => 'page', 'title' => 'Hizmetler', 'status' => 'publish', 'acf' => ['ozet' => 'Kısa'], 'featured_image' => 'ref:img-logo'],
            ['op' => 'menu', 'name' => 'Ana menü', 'items' => [['ref' => 'page-hizmetler']], 'location' => 'primary'],
        ];
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => $operations])->assertOk()->assertSee('partial');

        [$method, $url, $body] = $this->sent[1];
        $this->assertSame(['POST', 'https://example.com/wp-json/moxdop/v1/build'], [$method, $url]);
        $this->assertSame($operations, $body['operations'], 'every field of every operation reaches the site unchanged');
        $action = ExternalWriteAction::query()->sole();
        $this->assertSame([ExternalWriteAction::ACTION_SITE_BUILD, 'partial', $this->admin->id], [$action->action, $action->status, (int) $action->requested_by]);
        $this->assertSame('[12 bayt base64]', $action->request_payload['operations'][0]['data_base64'], 'file bytes are not stored');
        $this->assertFalse($action->isUndoable());

        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)->call('toggleClaudeBuild');
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'trash', 'ref' => 'page-hizmetler']]])->assertHasErrors();
        $this->assertCount(2, $this->sent);
    }

    public function test_the_plugin_must_allow_building_and_only_admins_switch_it(): void
    {
        $this->connection->forceFill(['config' => array_merge($this->connection->config, ['capabilities' => ['drafts'], 'claude_build' => ['enabled' => true, 'by' => $this->admin->id]])])->save();
        $this->statusCapabilities = ['drafts'];
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'trash', 'ref' => 'x']]])->assertHasErrors(['Site building']);
        $this->assertSame(['GET'], array_column($this->sent, 0), 'only the status was asked again, nothing was built');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)
            ->assertDontSee('Kapalı · aç')->call('toggleClaudeBuild')->assertForbidden();
    }
}
