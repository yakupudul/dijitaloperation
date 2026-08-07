<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\InstagramAccountProfileCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramAccountProfileCollectTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_collect_creates_account_profile_evidence_with_website(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001*' => Http::response([
                'id' => '17841400000000001',
                'username' => 'acme_brand',
                'name' => 'Acme Brand',
                'account_type' => 'BUSINESS',
                'website' => 'https://www.acme.example/',
                'biography' => 'We sell widgets.',
            ], 200),
        ]);

        $connection = $this->makeConnection([
            'ig_user_id' => '17841400000000001',
        ]);

        $run = app(InstagramAccountProfileCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(InstagramAccountProfileCollectService::MODULE_ID, $run->module_id);
        $this->assertTrue($run->metadata['collect_ok'] ?? false);
        $this->assertTrue($run->metadata['has_website'] ?? false);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('17841400000000001', $evidence->payload['requested_ig_user_id']);
        $this->assertSame('acme_brand', $evidence->payload['username']);
        $this->assertSame('https://www.acme.example/', $evidence->payload['website']);
        $this->assertSame('www.acme.example', $evidence->payload['website_host']);
        $this->assertSame('We sell widgets.', $evidence->payload['biography']);
        $this->assertSame('instagram_graph_ig_user_get', $evidence->payload['fetch_method']);

        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('EAAB.test-instagram-access-token', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/17841400000000001')
                && $request->hasHeader('Authorization')
                && str_contains($request->url(), 'fields=')
                && str_contains(urldecode($request->url()), 'website');
        });
    }

    public function test_missing_website_still_ok_with_null_website_fields(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000002*' => Http::response([
                'id' => '17841400000000002',
                'username' => 'no_site',
                'name' => 'No Site',
                'account_type' => 'CREATOR',
            ], 200),
        ]);

        $connection = $this->makeConnection(['ig_user_id' => '17841400000000002']);

        $run = app(InstagramAccountProfileCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertNull($evidence->payload['website']);
        $this->assertNull($evidence->payload['website_host']);
        $this->assertFalse($run->metadata['has_website'] ?? true);
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

        $connection = $this->makeConnection(['ig_user_id' => '17841400000000099']);

        $run = app(InstagramAccountProfileCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->metadata['collect_ok'] ?? true);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('account_not_accessible', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertSame('account_not_accessible', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('network down');
        });

        $connection = $this->makeConnection(['ig_user_id' => '17841400000000001']);

        $run = app(InstagramAccountProfileCollectService::class)->collect(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $this->assertStringContainsString('connection', (string) $evidence->payload['status_or_error']);
    }

    public function test_rejects_wrong_connection_type(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'instagram']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'meta_ads_api',
            'enabled' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instagram_graph_api');

        app(InstagramAccountProfileCollectService::class)->collect($connection);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeConnection(array $config): CoreConnection
    {
        $asset = DigitalAsset::factory()->create([
            'type' => InstagramAccountProfileCollectService::ASSET_TYPE,
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => InstagramAccountProfileCollectService::CONNECTION_TYPE,
            'name' => 'Instagram profile',
            'config' => $config,
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'access_token' => 'EAAB.test-instagram-access-token',
            ],
        ]);

        return $connection;
    }
}
