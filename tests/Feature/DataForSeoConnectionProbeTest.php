<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\DataForSeoConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSeoConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_account_probe_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20250526',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0,
                'tasks_count' => 1,
                'tasks_error' => 0,
                'tasks' => [
                    [
                        'id' => '05291903-1535-0064-0000-b2e6429ff433',
                        'status_code' => 20000,
                        'status_message' => 'Ok.',
                        'cost' => 0,
                        'result_count' => 1,
                        'result' => [
                            [
                                'login' => 'agency@example.com',
                                'timezone' => 'Europe/Istanbul',
                                'money' => [
                                    'total' => 100.0,
                                    'balance' => 42.5,
                                ],
                                'rates' => [
                                    'limits' => ['day' => ['serp' => ['task_post' => 0]]],
                                ],
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
            'type' => DataForSeoConnectionProbeService::CONNECTION_TYPE,
            'name' => 'DataForSEO Example',
            'config' => [],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'agency@example.com',
                'password' => 'super-secret-dfs-password',
            ],
        ]);

        $run = app(DataForSeoConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(DataForSeoConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);
        $this->assertSame('appendix-user-data', $run->metadata['probe']);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', DataForSeoConnectionProbeService::EVIDENCE_TYPE_DATAFORSEO_ACCOUNT)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('agency@example.com', $evidence->payload['account_login']);
        $this->assertSame('Europe/Istanbul', $evidence->payload['timezone']);
        $this->assertSame(42.5, $evidence->payload['balance']);
        $this->assertSame(20000, $evidence->payload['api_status_code']);
        $this->assertArrayNotHasKey('rates', $evidence->payload);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('super-secret-dfs-password', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.dataforseo.com/v3/appendix/user_data'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_auth_failure_sets_last_error(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 40101,
                'status_message' => 'Unauthorized.',
                'tasks' => [],
            ], 401),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'dataforseo',
            'config' => [],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'bad@example.com',
                'password' => 'wrong-password',
            ],
        ]);

        $run = app(DataForSeoConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('auth_failed', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('auth_failed', $connection->last_error);
    }

    public function test_account_data_missing_sets_last_error(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'status_message' => 'Ok.',
                        'result' => [
                            [
                                'timezone' => 'UTC',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'website', 'primary_url' => 'https://ex.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'dataforseo',
            'config' => [],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'login@example.com',
                'password' => 'password',
            ],
        ]);

        $run = app(DataForSeoConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('account_data_missing', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('account_data_missing', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'website', 'primary_url' => 'https://ex.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'dataforseo',
            'config' => [],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'login@example.com',
                'password' => 'password',
            ],
        ]);

        $run = app(DataForSeoConnectionProbeService::class)->probe(
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
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'dataforseo',
            'enabled' => true,
            'config' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(DataForSeoConnectionProbeService::class)->probe($connection);
    }

    public function test_rejects_incomplete_credentials(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'dataforseo',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'login@example.com',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(DataForSeoConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'login' => 'ro@example.com',
                                'timezone' => 'UTC',
                                'money' => ['balance' => 1.0],
                            ],
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
            'type' => 'dataforseo',
            'config' => [],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'login' => 'ro@example.com',
                'password' => 'ro-password',
            ],
        ]);

        app(DataForSeoConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
