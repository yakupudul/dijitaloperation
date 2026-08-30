<?php

namespace Tests\Feature;

use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnectionCredential;
use App\Services\Analysis\Adapters\WordPressCollectedFactsEvaluator;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WordPressConnectorV1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'cms' => 'wordpress',
            'domain' => 'example.com',
            'primary_url' => 'https://example.com/',
        ]);
    }

    #[Test]
    public function admin_can_issue_and_site_can_complete_one_time_pairing(): void
    {
        $issued = app(WordPressConnectorPairingService::class)->issue($this->asset, $this->admin);
        $connection = $issued['connection'];

        $this->assertStringStartsWith('MXD-'.$connection->id.'-', $issued['code']);
        $this->assertStringNotContainsString($issued['code'], json_encode($connection->config, JSON_THROW_ON_ERROR));
        $this->assertNull($connection->credential);

        $payload = $this->pairingPayload($issued['code']);
        $response = $this->postJson('/api/connectors/wordpress/pair', $payload);
        $response->assertCreated()->assertJsonPath('data.signature_algorithm', 'hmac-sha256');
        $secret = (string) $response->json('data.shared_secret');
        $this->assertGreaterThanOrEqual(40, strlen($secret));

        $connection->refresh()->load('credential');
        $this->assertTrue($connection->enabled);
        $this->assertSame(WordPressConnectorPairingService::PAIRED, $connection->config['pairing_state']);
        $this->assertSame($secret, $connection->credential->encrypted_payload['shared_secret']);
        $this->assertArrayNotHasKey('encrypted_payload', $connection->credential->toArray());

        $this->postJson('/api/connectors/wordpress/pair', $payload)->assertUnprocessable();
    }

    #[Test]
    public function pairing_rejects_domain_or_endpoint_substitution(): void
    {
        $issued = app(WordPressConnectorPairingService::class)->issue($this->asset, $this->admin);

        $wrongHost = $this->pairingPayload($issued['code']);
        $wrongHost['site_url'] = 'https://attacker.example/';
        $this->postJson('/api/connectors/wordpress/pair', $wrongHost)->assertUnprocessable();

        $wrongPath = $this->pairingPayload($issued['code']);
        $wrongPath['snapshot_url'] = 'https://example.com/wp-json/other/v1/snapshot';
        $this->postJson('/api/connectors/wordpress/pair', $wrongPath)->assertUnprocessable();
    }

    #[Test]
    public function team_member_cannot_issue_pairing_credentials(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->expectException(InvalidArgumentException::class);
        app(WordPressConnectorPairingService::class)->issue($this->asset, $member);
    }

    #[Test]
    public function issuing_a_rotation_does_not_interrupt_the_live_credential(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $first = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($first['code']));

        $second = $pairing->issue($this->asset, $this->admin);
        $connection = $second['connection']->fresh('credential');

        $this->assertTrue($connection->enabled);
        $this->assertNotNull($connection->credential);
        $this->assertSame(WordPressConnectorPairingService::PAIRED, $connection->config['pairing_state']);
        $this->assertTrue($connection->config['pairing_rotation_pending']);
    }

    #[Test]
    public function admin_can_revoke_a_pairing_and_member_cannot(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        try {
            $pairing->revoke($this->asset, $member);
            $this->fail('A Team Member revoked a connector credential.');
        } catch (InvalidArgumentException) {
            $this->assertNotNull($issued['connection']->fresh('credential')->credential);
        }

        $pairing->revoke($this->asset, $this->admin);
        $connection = $issued['connection']->fresh('credential');
        $this->assertFalse($connection->enabled);
        $this->assertNull($connection->credential);
        $this->assertSame(WordPressConnectorPairingService::DISCONNECTED, $connection->config['pairing_state']);
    }

    #[Test]
    public function client_signs_request_and_rejects_unsigned_response(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $credentials = $pairing->complete($this->pairingPayload($issued['code']));
        $connection = CoreConnection::query()->with('credential')->findOrFail($issued['connection']->id);
        $canonicalJson = new WordPressConnectorCanonicalJson;

        Http::fake(function (Request $request) use ($credentials, $canonicalJson) {
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = ['schema_version' => 1, 'wordpress_version' => '6.8', 'read_only' => true];
            $serverTime = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [
                (string) $serverTime,
                $nonce,
                hash('sha256', $canonicalJson->encode($data)),
            ]), $credentials['shared_secret']);

            return Http::response(['data' => $data, 'meta' => [
                'server_time' => $serverTime,
                'request_nonce' => $nonce,
                'signature' => $signature,
            ]]);
        });

        $client = new WordPressConnectorClient(
            $canonicalJson,
            new PublicUrlSafety(fn (string $host): array => ['93.184.216.34']),
        );
        $this->assertSame('6.8', $client->status($connection)['wordpress_version']);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader(WordPressConnectorClient::HEADER_SIGNATURE)
                && $request->hasHeader(WordPressConnectorClient::HEADER_CLIENT)
                && $request->url() === 'https://example.com/wp-json/moxdop/v1/status';
        });

        Http::fake([ '*' => Http::response(['data' => [], 'meta' => [
            'server_time' => now()->timestamp,
            'request_nonce' => 'wrong',
            'signature' => str_repeat('0', 64),
        ]])]);
        $this->expectException(\RuntimeException::class);
        $client->status($connection->fresh('credential'));
    }

    #[Test]
    public function operator_page_exposes_real_package_and_pairing_flow(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])
            ->set('selectedAssetId', $this->asset->id)
            ->assertSee('moxdop-wordpress-connector-1.0.0.zip')
            ->assertDontSee('DEMO CONNECTOR PACKAGE')
            ->call('issuePairingCode')
            ->assertSet('messageTone', 'success');
    }

    #[Test]
    public function paired_wordpress_collection_keeps_public_discovery_and_adds_connector_family(): void
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => WordPressConnectorPairingService::CONNECTION_TYPE,
            'enabled' => true,
            'last_success_at' => now(),
            'config' => ['pairing_state' => WordPressConnectorPairingService::PAIRED],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['client_id' => fake()->uuid(), 'shared_secret' => str_repeat('a', 43)],
        ]);

        $run = app(WebsiteCollectionOrchestrator::class)->start($this->asset, $this->admin);
        $families = $run->fresh('datasetRuns')->datasetRuns->pluck('request_family_id')->all();
        $wordpressDatasets = $run->datasetRuns
            ->where('request_family_id', WebsiteRequestFamilyCatalog::FAMILY_WP_REST)
            ->pluck('dataset_contract_id')
            ->sort()
            ->values()
            ->all();

        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $families);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $families);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $families);
        $this->assertSame([
            'website_cms_extension_snapshot',
            'website_cms_object_snapshot',
            'website_cms_seo_snapshot',
            'website_cms_site_snapshot',
            'website_cms_taxonomy_snapshot',
        ], $wordpressDatasets);
    }

    #[Test]
    public function connector_and_public_snapshots_produce_deterministic_parity_matches(): void
    {
        $collection = CollectionRun::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'digital_asset_id' => $this->asset->id,
        ]);
        $connectorResource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $collection->id,
            'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'status' => CollectionRunStatus::Completed,
        ]);
        $connectorRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $collection->id,
            'collection_resource_run_id' => $connectorResource->id,
            'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            'dataset_contract_id' => 'website_cms_site_snapshot',
            'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_WP_REST,
            'status' => CollectionRunStatus::Completed,
        ]);
        $publicResource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $collection->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'status' => CollectionRunStatus::Completed,
        ]);
        $publicRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $collection->id,
            'collection_resource_run_id' => $publicResource->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => 'website_metadata_snapshot',
            'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            'status' => CollectionRunStatus::Completed,
        ]);
        $observedAt = '2026-08-29 22:00:00';
        $provenance = [
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $collection->id,
            'last_dataset_run_id' => $connectorRun->id,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'connector-fixture'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('website_cms_site_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'site_key' => 'install-1', 'site_url' => 'https://example.com/',
            'home_url' => 'https://example.com/', 'wordpress_version' => '6.8', 'php_version' => '8.3',
            'locale' => 'en_US', 'timezone' => 'UTC', 'active_theme' => 'twentytwentyfive',
            'is_multisite' => false, 'rest_state' => 'reachable', 'cron_state' => 'enabled',
            'metadata' => json_encode(['core_update_available' => false]),
        ]));
        DB::table('website_cms_extension_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'extension_type' => 'plugin', 'extension_id' => 'seo/seo.php',
            'name' => 'SEO Plugin', 'version' => '1.0', 'status' => 'active', 'update_available' => true,
            'available_version' => '1.1', 'auto_update' => false, 'record_fingerprint' => hash('sha256', 'extension-fixture'),
            'metadata' => json_encode(['update_checked_at' => '2026-08-29T21:55:00Z']),
        ]));
        DB::table('website_cms_seo_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '10',
            'permalink' => 'https://example.com/', 'seo_provider' => 'yoast', 'seo_title' => 'Configured title',
            'meta_description' => 'Configured description', 'canonical_url' => 'https://example.com/',
            'robots' => null, 'language' => 'en', 'record_fingerprint' => hash('sha256', 'seo-fixture'),
            'metadata' => json_encode([]),
        ]));
        DB::table('website_metadata_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'url' => 'https://example.com/',
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $collection->id,
            'last_dataset_run_id' => $publicRun->id,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'public-fixture'),
            'metadata' => json_encode(['title_present' => false, 'meta_description_present' => false, 'canonical_hrefs' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(WordPressCollectedFactsEvaluator::class)->evaluate($this->asset);
        $fingerprints = array_map(fn ($match): string => $match->fingerprint, $result['matches']);
        $this->assertTrue($result['evaluated']);
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_PLUGIN_UPDATE)));
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_SEO_DESCRIPTION_PARITY)));
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_SEO_TITLE_PARITY)));
    }

    /** @return array<string, string> */
    private function pairingPayload(string $code): array
    {
        return [
            'pairing_code' => $code,
            'site_url' => 'https://example.com/',
            'home_url' => 'https://example.com/',
            'status_url' => 'https://example.com/wp-json/moxdop/v1/status',
            'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot',
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
            'plugin_version' => '1.0.0',
        ];
    }
}
