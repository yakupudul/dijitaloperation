<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\MetaAdsAdDestinationUrlsCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAdsAdDestinationUrlsCollectTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_collect_creates_destination_urls_evidence(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_1111111111/ads*' => Http::response([
                'data' => [
                    [
                        'id' => '1',
                        'status' => 'ACTIVE',
                        'creative' => [
                            'id' => 'c1',
                            'link_url' => 'https://www.acme.example/landing',
                            'object_story_spec' => [
                                'link_data' => [
                                    'link' => 'https://www.acme.example/offer',
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => '2',
                        'status' => 'PAUSED',
                        'creative' => [
                            'id' => 'c2',
                            'asset_feed_spec' => [
                                'link_urls' => [
                                    ['website_url' => 'https://promo.example/path'],
                                ],
                            ],
                            'object_story_spec' => [
                                'video_data' => [
                                    'call_to_action' => [
                                        'value' => [
                                            'link' => 'https://promo.example/video',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $connection = $this->makeConnection([
            'ad_account_id' => 'act_1111111111',
        ]);

        $run = app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(MetaAdsAdDestinationUrlsCollectService::MODULE_ID, $run->module_id);
        $this->assertTrue($run->metadata['collect_ok'] ?? false);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('act_1111111111', $evidence->payload['requested_ad_account_id']);
        $this->assertSame(4, $evidence->payload['destination_url_count']);
        $this->assertContains('https://www.acme.example/landing', $evidence->payload['destination_urls']);
        $this->assertContains('https://promo.example/path', $evidence->payload['destination_urls']);
        $this->assertContains('www.acme.example', $evidence->payload['destination_url_hosts']);
        $this->assertContains('promo.example', $evidence->payload['destination_url_hosts']);
        $this->assertSame('meta_ads_ads_list_get', $evidence->payload['fetch_method']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('EAAB.test-meta-ads-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/act_1111111111/ads')
                && $request->hasHeader('Authorization')
                && str_contains($request->url(), 'fields=')
                && str_contains(urldecode($request->url()), 'creative');
        });
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }

    public function test_empty_results_still_ok_with_zero_urls(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_2222222222/ads*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $connection = $this->makeConnection(['ad_account_id' => '2222222222']);

        $run = app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame(0, $evidence->payload['destination_url_count']);
        $this->assertSame([], $evidence->payload['destination_urls']);
        $this->assertSame('act_2222222222', $evidence->payload['requested_ad_account_id']);
    }

    public function test_api_error_records_last_error_without_credentials_in_evidence(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_3333333333/ads*' => Http::response([
                'error' => ['message' => '(#200) Permissions error'],
            ], 403),
        ]);

        $connection = $this->makeConnection(['ad_account_id' => 'act_3333333333']);

        $run = app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->metadata['collect_ok'] ?? true);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->payload['ok']);
        $this->assertStringContainsString('api_error', (string) $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertStringContainsString('api_error', (string) $connection->last_error);
        $this->assertStringNotContainsString('EAAB', (string) json_encode($evidence->payload));
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('meta ads offline');
        });

        $connection = $this->makeConnection(['ad_account_id' => 'act_4444444444']);

        $run = app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS)
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
            'type' => MetaAdsAdDestinationUrlsCollectService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => ['ad_account_id' => 'act_1'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('meta_ads Digital Asset');

        app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_missing_credentials(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => MetaAdsAdDestinationUrlsCollectService::ASSET_TYPE,
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => MetaAdsAdDestinationUrlsCollectService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => ['ad_account_id' => 'act_1'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encrypted access_token');

        app(MetaAdsAdDestinationUrlsCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    private function makeConnection(array $config): CoreConnection
    {
        $asset = DigitalAsset::factory()->create([
            'type' => MetaAdsAdDestinationUrlsCollectService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => MetaAdsAdDestinationUrlsCollectService::CONNECTION_TYPE,
            'name' => 'Meta Ads Destinations',
            'config' => $config,
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'EAAB.test-meta-ads-access-token',
            ],
        ]);

        return $connection;
    }
}
