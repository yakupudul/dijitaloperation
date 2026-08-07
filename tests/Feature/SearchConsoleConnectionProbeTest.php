<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\SearchConsoleConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchConsoleConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_property_match_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    [
                        'siteUrl' => 'https://example.com/',
                        'permissionLevel' => 'siteFullUser',
                    ],
                    [
                        'siteUrl' => 'sc-domain:other.com',
                        'permissionLevel' => 'siteOwner',
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
            'type' => SearchConsoleConnectionProbeService::CONNECTION_TYPE,
            'name' => 'GSC Example',
            'config' => ['site_url' => 'https://example.com/'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'ya29.test-access-token',
            ],
        ]);

        $run = app(SearchConsoleConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(SearchConsoleConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', SearchConsoleConnectionProbeService::EVIDENCE_TYPE_GSC_PROPERTY)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('https://example.com/', $evidence->payload['matched_site_url']);
        $this->assertSame('siteFullUser', $evidence->payload['permission_level']);
        $this->assertSame(2, $evidence->payload['site_count']);
        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('ya29.test-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://www.googleapis.com/webmasters/v3/sites'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_property_not_listed_sets_last_error(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://other.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'search_console',
            'config' => ['site_url' => 'https://missing.example/'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token-xyz'],
        ]);

        $run = app(SearchConsoleConnectionProbeService::class)->probe(
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
            'type' => 'search_console',
            'config' => ['site_url' => 'https://ex.com/'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'token'],
        ]);

        $run = app(SearchConsoleConnectionProbeService::class)->probe(
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
            'type' => 'search_console',
            'enabled' => true,
            'config' => ['site_url' => 'https://ex.com/'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(SearchConsoleConnectionProbeService::class)->probe($connection);
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://ro.example/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ro.example',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'search_console',
            'config' => ['site_url' => 'https://ro.example/'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['access_token' => 'ro-token'],
        ]);

        app(SearchConsoleConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
