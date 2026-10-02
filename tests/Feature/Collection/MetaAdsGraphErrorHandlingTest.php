<?php

namespace Tests\Feature\Collection;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsGraphErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'synthetic-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
            'moxdop.meta.api_version' => 'v26.0',
        ]);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_method' => 'oauth',
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => 'valid',
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
            ],
        ]);
    }

    #[Test]
    public function campaign_code_100_with_http_500_retries_once_with_core_fields_and_succeeds(): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($calls === 1) {
                $this->assertStringContainsString('daily_budget', (string) ($query['fields'] ?? ''));

                return Http::response([
                    'error' => [
                        'message' => '(#100) Tried accessing an unsupported Campaign field',
                        'error_user_title' => 'Invalid field',
                        'error_user_msg' => 'One requested Campaign field is not available.',
                        'code' => 100,
                        'error_subcode' => 33,
                    ],
                ], 500);
            }

            $this->assertSame(
                'id,name,objective,status,effective_status,buying_type,start_time,stop_time',
                $query['fields'] ?? null,
            );

            return Http::response([
                'data' => [[
                    'id' => '1001',
                    'name' => 'Campaign A',
                    'objective' => 'OUTCOME_TRAFFIC',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'buying_type' => 'AUCTION',
                ]],
            ], 200);
        });

        $payload = app(MetaApiClient::class)->get($this->integration, 'act_11110001/campaigns', [
            'fields' => 'id,name,objective,status,effective_status,buying_type,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time',
            'limit' => 250,
        ]);

        $this->assertSame('1001', data_get($payload, 'data.0.id'));
        $this->assertSame(2, $calls);
        Http::assertSentCount(2);
    }

    #[Test]
    public function structured_code_100_http_500_preserves_graph_message_and_is_not_transient_http(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => "(#100) Filtering field 'id' with operation 'in' is not supported",
                    'error_user_title' => 'Invalid filter',
                    'error_user_msg' => 'Use a supported edge or object lookup.',
                    'code' => 100,
                    'error_subcode' => 1487390,
                ],
            ], 500),
        ]);

        try {
            app(MetaApiClient::class)->get($this->integration, 'act_11110001/adsets', [
                'fields' => 'id,name',
                'limit' => 100,
            ]);
            $this->fail('Expected MetaException was not thrown.');
        } catch (MetaException $exception) {
            $this->assertSame(MetaException::KIND_PROVIDER, $exception->kind);
            $this->assertSame(500, $exception->httpStatus);
            $this->assertSame(100, $exception->providerCode);
            $this->assertStringContainsString("Filtering field 'id'", $exception->getMessage());
            $this->assertStringContainsString('Invalid filter', $exception->getMessage());
            $this->assertStringContainsString('subcode 1487390', $exception->getMessage());
        }
    }

    #[Test]
    public function entity_edges_block_id_in_filters_before_any_provider_request(): void
    {
        Http::fake();

        foreach (['campaigns', 'adsets', 'ads', 'adcreatives'] as $edge) {
            try {
                app(MetaApiClient::class)->get($this->integration, 'act_11110001/'.$edge, [
                    'fields' => 'id,name',
                    'filtering' => json_encode([
                        ['field' => 'id', 'operator' => 'IN', 'value' => ['1001', '1002']],
                    ], JSON_THROW_ON_ERROR),
                ]);
                $this->fail('Expected unsupported id IN filter to be blocked for '.$edge.'.');
            } catch (MetaException $exception) {
                $this->assertSame(MetaException::KIND_PROVIDER, $exception->kind);
                $this->assertSame(100, $exception->providerCode);
                $this->assertStringContainsString('blocked before provider call', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
