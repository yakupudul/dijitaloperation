<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\Ga4ConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Ga4ConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_property_match_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries' => Http::response([
                'accountSummaries' => [
                    [
                        'name' => 'accountSummaries/111',
                        'account' => 'accounts/111',
                        'displayName' => 'Agency Account',
                        'propertySummaries' => [
                            [
                                'property' => 'properties/123456',
                                'displayName' => 'Example Property',
                                'propertyType' => 'PROPERTY_TYPE_ORDINARY',
                            ],
                            [
                                'property' => 'properties/999',
                                'displayName' => 'Other Property',
                                'propertyType' => 'PROPERTY_TYPE_ORDINARY',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://example.com',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => Ga4ConnectionProbeService::CONNECTION_TYPE,
            'name' => 'GA4 Example',
            'config' => ['property_id' => '123456'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.test-ga4-access-token',
            ],
        ]);

        $run = app(Ga4ConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(Ga4ConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', Ga4ConnectionProbeService::EVIDENCE_TYPE_GA4_PROPERTY)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('properties/123456', $evidence->payload['matched_property_id']);
        $this->assertSame('Example Property', $evidence->payload['display_name']);
        $this->assertSame('Agency Account', $evidence->payload['account_display_name']);
        $this->assertSame(2, $evidence->payload['property_count']);
        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('ya29.test-ga4-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_property_not_listed_sets_last_error(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries' => Http::response([
                'accountSummaries' => [
                    [
                        'displayName' => 'Only Other',
                        'propertySummaries' => [
                            ['property' => 'properties/111', 'displayName' => 'Other'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'config' => ['property_id' => 'properties/404404'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token-xyz'],
        ]);

        $run = app(Ga4ConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('property_not_found', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('property_not_found', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'website', 'primary_url' => 'https://ex.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'config' => ['property_id' => 'properties/1'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $run = app(Ga4ConnectionProbeService::class)->probe(
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
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'enabled' => true,
            'config' => ['property_id' => 'properties/1'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(Ga4ConnectionProbeService::class)->probe($connection);
    }

    public function test_rejects_missing_property_id(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(Ga4ConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries' => Http::response([
                'accountSummaries' => [
                    [
                        'displayName' => 'RO',
                        'propertySummaries' => [
                            ['property' => 'properties/77', 'displayName' => 'RO Prop'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ro.example',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'config' => ['property_id' => 'properties/77'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'ro-token'],
        ]);

        app(Ga4ConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
