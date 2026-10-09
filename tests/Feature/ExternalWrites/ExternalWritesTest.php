<?php

namespace Tests\Feature\ExternalWrites;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GoogleAdsNegativeListWriter;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-064: Admin-approved Google Ads shared negative list and WordPress draft — recorded, executed on the queue and
 * undoable. Everyone else gets 403. v2: payload-driven service API (the suggestion screens come with Faz 5–7).
 */
final class ExternalWritesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    public function test_parse_paste_format(): void
    {
        $parsed = GoogleAdsNegativeListWriter::parse("[Ücretsiz Diş Tedavisi]\n\"staj\"\nimplant nedir\n\n[kötü!terim]\n".str_repeat('a ', 12));
        $this->assertSame([
            ['text' => 'ücretsiz diş tedavisi', 'match_type' => 'EXACT'],
            ['text' => 'staj', 'match_type' => 'PHRASE'],
            ['text' => 'implant nedir', 'match_type' => 'EXACT'],
        ], $parsed['keywords']);
        $this->assertCount(2, $parsed['rejected'], 'invalid characters and >10 words are not sent');
    }

    public function test_admin_sends_negative_list_to_shared_set_and_undoes_it(): void
    {
        $asset = $this->googleAdsAsset();
        $mutations = [];
        Http::fake(function (Request $request) use (&$mutations) {
            $url = $request->url();
            if (str_contains($url, 'googleAds:search')) {
                $query = (string) ($request->data()['query'] ?? '');

                return Http::response(['results' => str_contains($query, 'FROM campaign WHERE') ? [['campaign' => ['resourceName' => 'customers/1112223333/campaigns/77']]] : []]);
            }
            if (str_contains($url, ':mutate')) {
                $service = str_contains($url, 'sharedSets') ? 'sharedSets' : (str_contains($url, 'sharedCriteria') ? 'sharedCriteria' : 'campaignSharedSets');
                $mutations[] = [$service, $request->data()];
                $ops = $request->data()['operations'] ?? [];

                return Http::response(['results' => array_map(fn ($op, $i) => ['resourceName' => $service === 'sharedSets' ? 'customers/1112223333/sharedSets/9' : 'customers/1112223333/'.$service.'/9~'.$i], $ops, array_keys($ops))]);
            }
            if (str_contains($url, 'oauth2')) {
                return Http::response(['access_token' => 'fresh', 'expires_in' => 3600]);
            }

            return Http::response([], 404);
        });
        $writes = app(ExternalWriteService::class);
        $lines = "[ücretsiz diş tedavisi]\n\"staj\"";

        // Team members cannot request the write.
        $this->assertFalse(ExternalWriteService::allowed($this->member, ExternalWriteAction::CHANNEL_GOOGLE_ADS));
        try {
            $writes->requestNegativeList($this->member, $asset, $lines);
            $this->fail('team member must be refused');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $action = $writes->requestNegativeList($this->admin, $asset, $lines);
        $action->refresh();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame($this->admin->id, $action->requested_by);
        $this->assertSame(['sharedSets', 'sharedCriteria', 'campaignSharedSets'], array_column($mutations, 0));
        $this->assertSame(['name' => 'MoxDOP negatifleri', 'type' => 'NEGATIVE_KEYWORDS'], $mutations[0][1]['operations'][0]['create']);
        $this->assertSame(['text' => 'staj', 'matchType' => 'PHRASE'], $mutations[1][1]['operations'][1]['create']['keyword']);
        $this->assertSame('customers/1112223333/campaigns/77', $mutations[2][1]['operations'][0]['create']['campaign']);
        $this->assertCount(2, $action->result['added']);
        foreach ($mutations as [$service]) {
            $this->assertContains($service, ['sharedSets', 'sharedCriteria', 'campaignSharedSets'], 'no campaign, budget or ad mutation');
        }

        $writes->requestUndo($this->admin, $action);
        $this->assertSame('undone', $action->fresh()->status);
        $this->assertSame(['remove' => 'customers/1112223333/sharedCriteria/9~0'], end($mutations)[1]['operations'][0]);

        config(['moxdop-external-writes.enabled' => false]);
        try {
            $writes->requestNegativeList($this->admin, $asset, $lines);
            $this->fail('kill switch must refuse');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Harici yazma kapalı', (string) collect($exception->errors())->flatten()->first());
        }
    }

    public function test_admin_sends_a_draft_skeleton_to_wordpress_and_undoes_it(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.2.0'],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $sent = [];
        Http::fake(function (Request $request) use (&$sent, $secret) {
            $sent[] = [$request->method(), $request->url(), json_decode($request->body(), true)];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = $request->method() === 'POST'
                ? ['schema_version' => 1, 'post_id' => 42, 'status' => 'draft', 'edit_url' => 'https://example.com/wp-admin/post.php?post=42&action=edit', 'preview_url' => '']
                : ['schema_version' => 1, 'post_id' => 42, 'status' => 'trash'];
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
        $draft = ['title' => 'İmplant Tedavisi Rehberi', 'content_html' => '<h2>İmplant nedir?</h2><p></p>', 'post_type' => 'post', 'excerpt' => 'Talep var.', 'reference' => 'suggestion-1'];
        $writes = app(ExternalWriteService::class);

        try {
            $writes->requestDraft($this->member, $site, $draft);
            $this->fail('team member must be refused');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $action = $writes->requestDraft($this->admin, $site, $draft);
        $action->refresh();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame(['POST', 'https://example.com/wp-json/moxdop/v1/drafts'], [$sent[0][0], $sent[0][1]]);
        $this->assertSame('İmplant Tedavisi Rehberi', $sent[0][2]['title']);
        $this->assertSame('post', $sent[0][2]['post_type']);
        $this->assertStringContainsString('<h2>İmplant nedir?</h2>', $sent[0][2]['content_html']);
        $this->assertSame('suggestion-1', $sent[0][2]['reference']);
        $this->assertSame(42, $action->result['post_id']);

        $writes->requestUndo($this->admin, $action);
        $this->assertSame('undone', $action->fresh()->status);
        $this->assertSame(['DELETE', 'https://example.com/wp-json/moxdop/v1/drafts/42'], [$sent[1][0], $sent[1][1]]);

        // Old plugin: refused before anything is sent.
        $connection->forceFill(['config' => array_merge($connection->config, ['plugin_version' => '1.1.0'])])->save();
        try {
            $writes->requestDraft($this->admin, $site, $draft);
            $this->fail('old plugin must be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('en az 1.2.0', (string) collect($exception->errors())->flatten()->first());
        }
    }

    public function test_plugin_forces_drafts_and_refuses_published_content(): void
    {
        $controller = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-rest-controller.php'));
        $this->assertStringContainsString("'post_status' => 'draft'", $controller);
        $create = substr($controller, (int) strpos($controller, 'public function create_draft'), (int) strpos($controller, 'public function trash_draft') - (int) strpos($controller, 'public function create_draft'));
        $this->assertStringNotContainsString('publish', $create, 'the write endpoint never publishes');
        $this->assertStringNotContainsString('wp_update_post', $controller);
        $this->assertStringContainsString("get_post_meta(\$post_id, '_moxdop_created', true) !== '1'", $controller);
        $this->assertStringContainsString("define('MOXDOP_CONNECTOR_VERSION', '1.12.0')", file_get_contents(base_path('connectors/wordpress/moxdop-connector/moxdop-connector.php')));
    }

    private function googleAdsAsset(): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return $asset;
    }
}
