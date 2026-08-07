<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\MetaAdsConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAdsConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_ad_account_access_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_111222333*' => Http::response([
                'id' => 'act_111222333',
                'name' => 'Acme Meta Ads',
                'account_status' => 1,
                'currency' => 'USD',
                'timezone_name' => 'America/Los_Angeles',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => MetaAdsConnectionProbeService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => MetaAdsConnectionProbeService::CONNECTION_TYPE,
            'name' => 'Meta Ads Acme',
            'config' => [
                'ad_account_id' => 'act_111222333',
            ],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'EAAB.test-meta-access-token',
            ],
        ]);

        $run = app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(MetaAdsConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', MetaAdsConnectionProbeService::EVIDENCE_TYPE_META_ADS_ACCOUNT_ACCESS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('act_111222333', $evidence->payload['requested_ad_account_id']);
        $this->assertSame('act_111222333', $evidence->payload['ad_account_id']);
        $this->assertSame('Acme Meta Ads', $evidence->payload['name']);
        $this->assertSame(1, $evidence->payload['account_status']);
        $this->assertSame('USD', $evidence->payload['currency']);
        $this->assertSame('America/Los_Angeles', $evidence->payload['timezone_name']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('EAAB.test-meta-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/act_111222333')
                && $request->hasHeader('Authorization')
                && str_contains($request->url(), 'fields=');
        });
    }

    public function test_numeric_ad_account_id_is_normalized(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_444555666*' => Http::response([
                'id' => 'act_444555666',
                'name' => 'Numeric Account',
                'account_status' => 1,
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'config' => ['ad_account_id' => '444555666'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $run = app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('act_444555666', $evidence->payload['requested_ad_account_id']);
    }

    public function test_account_not_accessible_sets_last_error(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_111111111*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request.',
                    'type' => 'GraphMethodException',
                    'code' => 100,
                ],
            ], 404),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'config' => ['ad_account_id' => 'act_111111111'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $run = app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('account_not_accessible', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('account_not_accessible', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'config' => ['ad_account_id' => 'act_1'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $run = app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $connection->refresh();
        $this->assertStringContainsString('connection', (string) $connection->last_error);
    }

    public function test_rejects_missing_credentials(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'enabled' => true,
            'config' => ['ad_account_id' => 'act_1'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'refresh_token' => 'not-an-access-token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_missing_ad_account_id(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_website_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
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

        app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/act_77*' => Http::response([
                'id' => 'act_77',
                'name' => 'Get Only',
                'account_status' => 1,
                'currency' => 'USD',
                'timezone_name' => 'UTC',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'config' => ['ad_account_id' => '77'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        app(MetaAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
