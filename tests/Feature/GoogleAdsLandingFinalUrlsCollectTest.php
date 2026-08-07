<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\GoogleAdsLandingFinalUrlsCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsLandingFinalUrlsCollectTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_collect_creates_landing_final_urls_evidence(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v18/customers/1111111111/googleAds:search' => Http::response([
                'results' => [
                    [
                        'adGroupAd' => [
                            'ad' => [
                                'finalUrls' => [
                                    'https://www.acme.example/landing',
                                    'https://www.acme.example/offer',
                                ],
                            ],
                        ],
                    ],
                    [
                        'adGroupAd' => [
                            'ad' => [
                                'finalUrls' => [
                                    'https://promo.example/path',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $connection = $this->makeConnection([
            'customer_id' => 'customers/1111111111',
            'login_customer_id' => '999-999-9999',
        ]);

        $run = app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(GoogleAdsLandingFinalUrlsCollectService::MODULE_ID, $run->module_id);
        $this->assertTrue($run->metadata['collect_ok'] ?? false);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('1111111111', $evidence->payload['requested_customer_id']);
        $this->assertSame(3, $evidence->payload['final_url_count']);
        $this->assertContains('https://www.acme.example/landing', $evidence->payload['final_urls']);
        $this->assertContains('www.acme.example', $evidence->payload['final_url_hosts']);
        $this->assertContains('promo.example', $evidence->payload['final_url_hosts']);
        $this->assertSame('google_ads_search_gaql', $evidence->payload['fetch_method']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('ya29.test-ads-access-token', (string) $encoded);
        $this->assertStringNotContainsString('test-developer-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://googleads.googleapis.com/v18/customers/1111111111/googleAds:search'
                && $request->hasHeader('Authorization')
                && $request->hasHeader('developer-token')
                && $request->header('login-customer-id')[0] === '9999999999'
                && is_array($body)
                && is_string($body['query'] ?? null)
                && str_contains($body['query'], 'ad_group_ad.ad.final_urls')
                && ! str_contains(strtolower($request->url()), 'mutate');
        });
    }

    public function test_empty_results_still_ok_with_zero_urls(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v18/customers/2222222222/googleAds:search' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $connection = $this->makeConnection(['customer_id' => '2222222222']);

        $run = app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame(0, $evidence->payload['final_url_count']);
        $this->assertSame([], $evidence->payload['final_urls']);
    }

    public function test_api_error_records_last_error_without_credentials_in_evidence(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v18/customers/3333333333/googleAds:search' => Http::response([
                'error' => ['message' => 'PERMISSION_DENIED'],
            ], 403),
        ]);

        $connection = $this->makeConnection(['customer_id' => '3333333333']);

        $run = app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->metadata['collect_ok'] ?? true);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('403', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertSame('403', $connection->last_error);
        $this->assertStringNotContainsString('ya29', (string) json_encode($evidence->payload));
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('ads offline');
        });

        $connection = $this->makeConnection(['customer_id' => '4444444444']);

        $run = app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $this->assertStringContainsString('connection', (string) $evidence->payload['status_or_error']);
    }

    public function test_rejects_wrong_asset_type(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => GoogleAdsLandingFinalUrlsCollectService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => ['customer_id' => '1111111111'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.x',
                'developer_token' => 'dev',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('google_ads Digital Asset');

        app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_missing_credentials(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => GoogleAdsLandingFinalUrlsCollectService::ASSET_TYPE,
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => GoogleAdsLandingFinalUrlsCollectService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => ['customer_id' => '1111111111'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encrypted access_token and developer_token');

        app(GoogleAdsLandingFinalUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    private function makeConnection(array $config): CoreConnection
    {
        $asset = DigitalAsset::factory()->create([
            'type' => GoogleAdsLandingFinalUrlsCollectService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => GoogleAdsLandingFinalUrlsCollectService::CONNECTION_TYPE,
            'name' => 'Ads Landing',
            'config' => $config,
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.test-ads-access-token',
                'developer_token' => 'test-developer-token',
            ],
        ]);

        return $connection;
    }
}
