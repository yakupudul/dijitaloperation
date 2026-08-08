<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\GoogleAdsConnectionProbeService;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_customer_access_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([
                'resourceNames' => [
                    'customers/1111111111',
                    'customers/2222222222',
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => GoogleAdsConnectionProbeService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => GoogleAdsConnectionProbeService::CONNECTION_TYPE,
            'name' => 'Ads Acme',
            'config' => [
                'customer_id' => 'customers/1111111111',
                'login_customer_id' => '999-999-9999',
            ],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.test-ads-access-token',
                'developer_token' => 'test-developer-token',
            ],
        ]);

        $run = app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(GoogleAdsConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleAdsConnectionProbeService::EVIDENCE_TYPE_GOOGLE_ADS_ACCOUNT_ACCESS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('1111111111', $evidence->payload['requested_customer_id']);
        $this->assertSame('customers/1111111111', $evidence->payload['matched_customer_resource']);
        $this->assertSame(2, $evidence->payload['accessible_customer_count']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('ya29.test-ads-access-token', (string) $encoded);
        $this->assertStringNotContainsString('test-developer-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers')
                && $request->hasHeader('Authorization')
                && $request->hasHeader('developer-token')
                && $request->header('login-customer-id')[0] === '9999999999';
        });
    }

    public function test_numeric_customer_id_is_accepted(): void
    {
        Http::fake([
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([
                'resourceNames' => ['customers/3333333333'],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'config' => ['customer_id' => '333-333-3333'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        $run = app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('3333333333', $evidence->payload['requested_customer_id']);
    }

    public function test_customer_not_accessible_sets_last_error(): void
    {
        Http::fake([
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([
                'resourceNames' => ['customers/9999999999'],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'config' => ['customer_id' => '1111111111'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        $run = app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('customer_not_accessible', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('customer_not_accessible', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'config' => ['customer_id' => '1'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        $run = app(GoogleAdsConnectionProbeService::class)->probe(
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
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'enabled' => true,
            'config' => ['customer_id' => '1'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token-only',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_missing_customer_id(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_website_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'enabled' => true,
            'config' => ['customer_id' => '1'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([
                'resourceNames' => ['customers/77'],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_ads_api',
            'config' => ['customer_id' => '77'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
                'developer_token' => 'dev',
            ],
        ]);

        app(GoogleAdsConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
