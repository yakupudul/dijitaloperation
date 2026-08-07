<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\InstagramConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_account_access_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001*' => Http::response([
                'id' => '17841400000000001',
                'username' => 'acme_brand',
                'name' => 'Acme Brand',
                'account_type' => 'BUSINESS',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => InstagramConnectionProbeService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => InstagramConnectionProbeService::CONNECTION_TYPE,
            'name' => 'Instagram Acme',
            'config' => [
                'ig_user_id' => '17841400000000001',
            ],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'EAAB.test-instagram-access-token',
            ],
        ]);

        $run = app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(InstagramConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', InstagramConnectionProbeService::EVIDENCE_TYPE_INSTAGRAM_ACCOUNT_ACCESS)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('17841400000000001', $evidence->payload['requested_ig_user_id']);
        $this->assertSame('17841400000000001', $evidence->payload['ig_user_id']);
        $this->assertSame('acme_brand', $evidence->payload['username']);
        $this->assertSame('Acme Brand', $evidence->payload['name']);
        $this->assertSame('BUSINESS', $evidence->payload['account_type']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('EAAB.test-instagram-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/17841400000000001')
                && $request->hasHeader('Authorization')
                && str_contains($request->url(), 'fields=');
        });
    }

    public function test_account_not_accessible_sets_last_error(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000099*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request.',
                    'type' => 'GraphMethodException',
                    'code' => 100,
                ],
            ], 404),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'config' => ['ig_user_id' => '17841400000000099'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $run = app(InstagramConnectionProbeService::class)->probe(
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

        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'config' => ['ig_user_id' => '17841400000000001'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $run = app(InstagramConnectionProbeService::class)->probe(
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
        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'enabled' => true,
            'config' => ['ig_user_id' => '17841400000000001'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'refresh_token' => 'not-an-access-token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_missing_ig_user_id(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
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

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_non_numeric_ig_user_id(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'enabled' => true,
            'config' => ['ig_user_id' => 'not-a-number'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_website_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'enabled' => true,
            'config' => ['ig_user_id' => '17841400000000001'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_meta_ads_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'enabled' => true,
            'config' => ['ig_user_id' => '17841400000000001'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000077*' => Http::response([
                'id' => '17841400000000077',
                'username' => 'get_only',
                'name' => 'Get Only',
                'account_type' => 'CREATOR',
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'instagram_graph_api',
            'config' => ['ig_user_id' => '17841400000000077'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'token',
            ],
        ]);

        app(InstagramConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
