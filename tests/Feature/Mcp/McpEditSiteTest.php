<?php

namespace Tests\Feature\Mcp;

use App\Enums\CustomerStatus;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\EditSite;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\SiteWrites;
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
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/**
 * edit-site: when the operator asks in the chat, Claude changes a brand's site through the same logged, undoable write
 * path as an approved Onarım masası row (SEO fields, alt text, internal link, draft copy, new draft); a brandless site gets nothing.
 */
final class McpEditSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $site;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'Klinik', 'domain' => 'example.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'status_url' => 'https://example.com/wp-json/moxdop/v1/status', 'plugin_version' => '1.26.0', 'capabilities' => ['drafts', 'fixes', 'content']],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        Http::fake(function (Request $request) use ($secret) {
            $body = json_decode($request->body(), true);
            $this->sent[] = [$request->method(), $request->url(), $body];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = match (true) {
                str_ends_with($request->url(), '/fixes/undo') => ['schema_version' => 1, 'results' => array_map(fn (string $id): array => ['change_id' => $id, 'ok' => true], $body['change_ids'])],
                str_ends_with($request->url(), '/fixes') => ['schema_version' => 1, 'results' => array_map(fn (array $c): array => ['ok' => true, 'type' => $c['type'], 'change_id' => 'chg-'.$c['type'], 'before' => 'eski'], $body['changes'])],
                default => ['schema_version' => 1, 'plugin_version' => '1.26.0', 'capabilities' => ['drafts', 'fixes', 'content']],
            };
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }

    public function test_list_sites_says_what_edit_site_may_change(): void
    {
        MoxdopServer::tool(ListSites::class, [])->assertOk()->assertSee('"fixes":true', false)->assertSee('"serviced":true', false);
    }

    public function test_seo_fixes_go_to_the_site_logged_and_undoable(): void
    {
        MoxdopServer::tool(EditSite::class, ['site_id' => $this->site->id, 'changes' => [
            ['type' => 'seo_title', 'object_id' => 12, 'value' => 'İmplant Tedavisi | Klinik'],
            ['type' => 'internal_link', 'object_id' => 12, 'value' => ['anchor' => 'implant', 'url' => 'https://example.com/implant/']],
        ]])->assertOk()->assertSee('action_id');

        $action = ExternalWriteAction::query()->sole();
        $this->assertSame([ExternalWriteAction::ACTION_SITE_FIX, $this->admin->id], [$action->action, (int) $action->requested_by]);
        $fixes = collect($this->sent)->first(fn (array $s): bool => str_ends_with($s[1], '/fixes'));
        $this->assertNotNull($fixes, 'the fixes reached the site');
        $this->assertSame(['seo_title', 'internal_link'], array_column($fixes[2]['changes'], 'type'));
        $this->assertSame(['anchor' => 'implant', 'url' => 'https://example.com/implant/'], $fixes[2]['changes'][1]['value']);
        $this->assertSame('succeeded', $action->fresh()->status);

        MoxdopServer::tool(SiteWrites::class, ['ids' => [$action->id]])->assertOk()->assertSee('chg-seo_title')->assertSee('"undoable":true', false);
        MoxdopServer::tool(EditSite::class, ['site_id' => $this->site->id, 'undo' => $action->id])->assertOk();
        $this->assertTrue(collect($this->sent)->contains(fn (array $s): bool => str_ends_with($s[1], '/fixes/undo')));
    }

    public function test_one_kind_per_call_and_no_edits_on_a_brandless_site(): void
    {
        MoxdopServer::tool(EditSite::class, ['site_id' => $this->site->id, 'changes' => [['type' => 'seo_title', 'object_id' => 1, 'value' => 'x']], 'undo' => 5])->assertHasErrors(['Bir çağrıda yalnız biri']);
        $this->site->forceFill(['brand_id' => null])->save();
        MoxdopServer::tool(EditSite::class, ['site_id' => $this->site->id, 'changes' => [['type' => 'seo_title', 'object_id' => 1, 'value' => 'x']]])->assertHasErrors();
        $this->assertSame(0, ExternalWriteAction::query()->count());
        $this->assertFalse(collect($this->sent)->contains(fn (array $s): bool => str_ends_with($s[1], '/fixes')));
    }
}
