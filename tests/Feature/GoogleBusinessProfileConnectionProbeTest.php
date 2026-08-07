<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\GoogleBusinessProfileConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleBusinessProfileConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_location_access_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://mybusinessbusinessinformation.googleapis.com/v1/locations/123*' => Http::response([
                'name' => 'locations/123',
                'title' => 'Acme Local Shop',
                'websiteUri' => 'https://acme.example',
                'phoneNumbers' => [
                    'primaryPhone' => '+1 555-0100',
                ],
                'categories' => [
                    'primaryCategory' => [
                        'displayName' => 'Coffee shop',
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => GoogleBusinessProfileConnectionProbeService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => GoogleBusinessProfileConnectionProbeService::CONNECTION_TYPE,
            'name' => 'GBP Acme',
            'config' => ['location_name' => 'locations/123'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.test-gbp-access-token',
            ],
        ]);

        $run = app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(GoogleBusinessProfileConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', GoogleBusinessProfileConnectionProbeService::EVIDENCE_TYPE_GBP_LOCATION_ACCESS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('locations/123', $evidence->payload['location_name']);
        $this->assertSame('Acme Local Shop', $evidence->payload['title']);
        $this->assertSame('https://acme.example', $evidence->payload['website_uri']);
        $this->assertSame('+1 555-0100', $evidence->payload['primary_phone']);
        $this->assertSame('Coffee shop', $evidence->payload['primary_category']);
        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('ya29.test-gbp-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://mybusinessbusinessinformation.googleapis.com/v1/locations/123')
                && $request->hasHeader('Authorization')
                && str_contains($request->url(), 'readMask=');
        });
    }

    public function test_numeric_location_id_is_normalized(): void
    {
        Http::fake([
            'https://mybusinessbusinessinformation.googleapis.com/v1/locations/999*' => Http::response([
                'name' => 'locations/999',
                'title' => 'Numeric Location',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'google_business_profile',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'config' => ['location_id' => '999'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $run = app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('locations/999', $evidence->payload['requested_location_name']);
    }

    public function test_location_not_found_sets_last_error(): void
    {
        Http::fake([
            'https://mybusinessbusinessinformation.googleapis.com/v1/locations/404*' => Http::response([
                'error' => ['message' => 'Not found'],
            ], 404),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'config' => ['location_name' => 'locations/404'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token-xyz'],
        ]);

        $run = app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('location_not_found', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('location_not_found', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'config' => ['location_name' => 'locations/1'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $run = app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $connection->refresh();
        $this->assertStringContainsString('connection', (string) $connection->last_error);
    }

    public function test_rejects_missing_access_token(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'enabled' => true,
            'config' => ['location_name' => 'locations/1'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleBusinessProfileConnectionProbeService::class)->probe($connection);
    }

    public function test_rejects_missing_location_identifier(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_website_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'enabled' => true,
            'config' => ['location_name' => 'locations/1'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://mybusinessbusinessinformation.googleapis.com/v1/locations/77*' => Http::response([
                'name' => 'locations/77',
                'title' => 'RO Location',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'google_business_profile_api',
            'config' => ['location_name' => 'locations/77'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        app(GoogleBusinessProfileConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
