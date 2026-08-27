<?php

namespace Tests\Feature\Collection;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaApiClientCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsCreativeLookupCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function legacy_adcreative_id_in_filter_is_translated_to_graph_multi_id_lookup(): void
    {
        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'synthetic-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
            'moxdop.meta.api_version' => 'v26.0',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_method' => 'oauth',
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => 'valid',
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
            ],
        ]);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertArrayHasKey('ids', $query);
            $this->assertSame('4001,4002', $query['ids']);
            $this->assertArrayNotHasKey('filtering', $query);
            $this->assertSame('id,name,title', $query['fields'] ?? null);

            return Http::response([
                '4001' => ['id' => '4001', 'name' => 'Creative A', 'title' => 'A'],
                '4002' => ['id' => '4002', 'name' => 'Creative B', 'title' => 'B'],
            ], 200);
        });

        $client = app(MetaApiClient::class);
        $this->assertInstanceOf(MetaApiClientCompatibility::class, $client);

        $payload = $client->get($integration, 'act_11110001/adcreatives', [
            'fields' => 'id,name,title',
            'limit' => 2,
            'filtering' => json_encode([
                ['field' => 'id', 'operator' => 'IN', 'value' => ['4001', '4002']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame([
            ['id' => '4001', 'name' => 'Creative A', 'title' => 'A'],
            ['id' => '4002', 'name' => 'Creative B', 'title' => 'B'],
        ], $payload['data']);

        Http::assertSentCount(1);
    }

    #[Test]
    public function unrelated_meta_requests_still_use_the_canonical_client_path_unchanged(): void
    {
        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'synthetic-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
            'moxdop.meta.api_version' => 'v26.0',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
            ],
        ]);

        Http::fake([
            '*' => Http::response([
                'data' => [['id' => '1001', 'name' => 'Campaign A']],
            ], 200),
        ]);

        $payload = app(MetaApiClient::class)->get($integration, 'act_11110001/campaigns', [
            'fields' => 'id,name',
            'limit' => 100,
        ]);

        $this->assertSame('1001', data_get($payload, 'data.0.id'));

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/act_11110001/campaigns')
                && ! array_key_exists('ids', $query);
        });
    }
}
