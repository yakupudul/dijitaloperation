<?php

namespace Tests\Feature\Mcp;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\BuildSite;
use App\Mcp\Tools\InspectSite;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\SiteBuilds;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    private bool $undoConflict = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        Storage::fake('local');
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
                str_ends_with($request->url(), '/build/undo') => ['schema_version' => 1, 'results' => array_map(fn (string $id): array => $this->undoConflict && ! json_decode($request->body(), true)['force']
                    ? ['change_id' => $id, 'ok' => false, 'error' => 'changed_since', 'post_id' => 10]
                    : ['change_id' => $id, 'ok' => true, 'errors' => []], json_decode($request->body(), true)['change_ids'])],
                default => ['schema_version' => 1, 'results' => array_map(fn (array $op): array => $op['op'] === 'menu'
                    ? ['op' => 'menu', 'ok' => false, 'errors' => ['theme has no menu location primary']]
                    : ['op' => $op['op'], 'ok' => true, 'ref' => $op['ref'] ?? null, 'id' => 10, 'created' => true, 'change_id' => 'chg-'.($op['ref'] ?? $op['op']), 'edit_url' => 'https://example.com/wp-admin/post.php?post=10&action=edit'], json_decode($request->body(), true)['operations'])],
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

    public function test_direct_mode_builds_at_once_and_the_admin_undoes_it_from_the_site_log(): void
    {
        $this->actingAs($this->admin);
        $panel = Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)
            ->call('setBuildMode', 'direct')->assertSee('Bu sitede henüz Claude kurulumu yok.');
        $this->assertSame('direct', data_get($this->connection->fresh()->config, 'claude_build.mode'));

        MoxdopServer::tool(ListSites::class, [])->assertOk()->assertSee('"ready":true', false)->assertSee('"mode":"direct"', false);
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
        $this->assertEquals($operations, $body['operations'], 'every field of every operation reaches the site unchanged');
        $action = ExternalWriteAction::query()->sole();
        $this->assertSame([ExternalWriteAction::ACTION_SITE_BUILD, 'partial', $this->admin->id], [$action->action, $action->status, (int) $action->requested_by]);
        $this->assertArrayNotHasKey('data_base64', $action->request_payload['operations'][0], 'file bytes are not stored in the database');
        $this->assertSame([], Storage::disk('local')->allFiles('site-build'), 'the file is gone once it was sent');
        $this->assertTrue($action->isUndoable());

        // The site log shows what was done; the admin undoes it, newest first.
        $panel->call('$refresh')->assertSee('Site kurulum kaydı')->assertSee('Yeni sayfa')->assertSee('Hizmetler · page-hizmetler')->assertSee('theme has no menu location primary')->assertSee('Geri al');
        $panel->call('undoBuild', $action->id);
        $this->assertSame(['https://example.com/wp-json/moxdop/v1/build/undo', ['change_ids' => ['chg-page-hizmetler', 'chg-img-logo'], 'force' => false]], [$this->sent[2][1], $this->sent[2][2]]);
        $this->assertSame('undone', $action->fresh()->status);
        MoxdopServer::tool(SiteBuilds::class, ['site_id' => $this->site->id])->assertOk()->assertSee('"status":"undone"', false);

        $panel->call('setBuildMode', 'off');
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'trash', 'ref' => 'page-hizmetler']]])->assertHasErrors();
        $this->assertCount(3, $this->sent);
    }

    public function test_approval_mode_waits_for_the_admin_who_approves_or_rejects(): void
    {
        $this->actingAs($this->admin);
        $panel = Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)->call('setBuildMode', 'approval');

        $logo = ['op' => 'media', 'ref' => 'img-logo', 'data_base64' => base64_encode('png-bytes'), 'filename' => 'logo.png'];
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [$logo]])->assertOk()->assertSee('awaiting_approval');
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'post', 'ref' => 'page-x', 'title' => 'Deneme', 'status' => 'publish']]])->assertOk();
        $this->assertSame([], $this->sent, 'nothing reaches the site before approval');
        [$second, $first] = ExternalWriteAction::query()->latest('id')->get()->all();
        $this->assertCount(1, Storage::disk('local')->allFiles('site-build'), 'the image waits on disk, not in the database');

        $panel->call('$refresh')->assertSee('Onay bekliyor')->assertSee('Onayla')->assertSee('durum: publish');
        $panel->call('approveBuild', $first->id);
        $this->assertSame('succeeded', $first->fresh()->status);
        $this->assertEquals([$logo], $this->sent[0][2]['operations'], 'the approved build sends the stored image');
        $this->assertSame($this->admin->id, data_get($first->fresh()->result, 'approved_by'));
        $this->assertSame([], Storage::disk('local')->allFiles('site-build'));

        $panel->set('rejectReasons.'.$second->id, 'Başlık yanlış')->call('rejectBuild', $second->id);
        $this->assertSame(WordPressSiteBuilder::REJECTED, $second->fresh()->status);
        $this->assertCount(1, $this->sent);
        MoxdopServer::tool(SiteBuilds::class, ['site_id' => $this->site->id])->assertOk()->assertSee('Başlık yanlış');
        $panel->call('approveBuild', $second->id)->assertSee('artık onay beklemiyor');

        // Changed on the site since: the undo stops, "Yine de geri al" forces it.
        $this->undoConflict = true;
        $panel->call('undoBuild', $first->id);
        $this->assertSame('undo_failed', $first->fresh()->status);
        $panel->call('$refresh')->assertSee('Yine de geri al')->call('forceUndoBuild', $first->id);
        $this->assertTrue(end($this->sent)[2]['force']);
        $this->assertSame('undone', $first->fresh()->status);
    }

    public function test_the_plugin_must_allow_building_and_only_admins_change_the_mode(): void
    {
        $this->connection->forceFill(['config' => array_merge($this->connection->config, ['capabilities' => ['drafts'], 'claude_build' => ['enabled' => true, 'mode' => 'direct', 'by' => $this->admin->id]])])->save();
        $this->statusCapabilities = ['drafts'];
        MoxdopServer::tool(BuildSite::class, ['site_id' => $this->site->id, 'operations' => [['op' => 'trash', 'ref' => 'x']]])->assertHasErrors(['Site building']);
        $this->assertSame(['GET'], array_column($this->sent, 0), 'only the status was asked again, nothing was built');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])->set('selectedAssetId', $this->site->id)
            ->call('setBuildMode', 'off')->assertForbidden();
        $this->assertSame('direct', data_get($this->connection->fresh()->config, 'claude_build.mode'));
    }
}
