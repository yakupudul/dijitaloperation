<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\EvidenceFreshnessDecision;
use App\Services\Integrations\EvidenceFreshnessGuard;
use App\Services\Integrations\PaidRequestFingerprint;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSeoCostGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->integration = CoreIntegration::factory()->dataforseo()->create();
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);
    }

    public function test_same_request_with_fresh_evidence_is_hit_and_skips_provider(): void
    {
        $fingerprint = PaidRequestFingerprint::make(
            'dataforseo',
            'keyword_metrics',
            'dataforseo_labs/google/keyword_overview/live',
            ['keyword' => 'shoes', 'location_code' => 2840, 'language_code' => 'en'],
        );

        $asset = DigitalAsset::factory()->create();
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
            'metadata' => ['ok' => true],
        ]);

        $evidence = Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'dfs_keyword_overview',
            'request_fingerprint' => $fingerprint,
            'payload' => ['ok' => true, 'keyword' => 'shoes'],
            'fresh_until' => now()->addDay(),
            'observed_at' => now(),
        ]);

        Http::fake();

        $result = app(EvidenceFreshnessGuard::class)->evaluate($fingerprint);

        $this->assertSame(EvidenceFreshnessDecision::HitFresh, $result['decision']);
        $this->assertTrue($result['decision']->isCacheHit());
        $this->assertFalse($result['decision']->allowsProviderCall());
        $this->assertSame(0.0, $result['reported_cost_usd']);
        $this->assertTrue($evidence->is($result['evidence']));

        $metadata = app(EvidenceFreshnessGuard::class)->cacheHitRunMetadata(
            'dataforseo',
            'keyword_metrics',
            $fingerprint,
            $evidence,
        );
        $this->assertTrue($metadata['provider_call_skipped']);
        $this->assertSame(0.0, $metadata['reported_cost_usd']);
        $this->assertArrayNotHasKey('password', $metadata);
        $this->assertArrayNotHasKey('login', $metadata);

        Http::assertNothingSent();
    }

    public function test_stale_evidence_is_miss_and_allows_provider_call(): void
    {
        $fingerprint = PaidRequestFingerprint::make(
            'dataforseo',
            'keyword_metrics',
            'dataforseo_labs/google/keyword_overview/live',
            ['keyword' => 'shoes', 'location_code' => 2840],
        );

        $asset = DigitalAsset::factory()->create();
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'request_fingerprint' => $fingerprint,
            'payload' => ['ok' => true],
            'fresh_until' => now()->subMinute(),
            'observed_at' => now()->subHour(),
        ]);

        $result = app(EvidenceFreshnessGuard::class)->evaluate($fingerprint);

        $this->assertSame(EvidenceFreshnessDecision::Miss, $result['decision']);
        $this->assertTrue($result['decision']->allowsProviderCall());
        $this->assertNull($result['evidence']);
    }

    public function test_different_markets_produce_different_fingerprints(): void
    {
        $turkey = PaidRequestFingerprint::make('dataforseo', 'serp', 'serp/google/organic/live/advanced', [
            'keyword' => 'ayakkabı',
            'location_code' => 2792,
            'language_code' => 'tr',
        ]);
        $germany = PaidRequestFingerprint::make('dataforseo', 'serp', 'serp/google/organic/live/advanced', [
            'keyword' => 'schuhe',
            'location_code' => 2276,
            'language_code' => 'de',
        ]);

        $this->assertNotSame($turkey, $germany);
    }

    public function test_parameter_ordering_does_not_change_fingerprint(): void
    {
        $a = PaidRequestFingerprint::make('dataforseo', 'serp', 'serp/google/organic/live/advanced', [
            'device' => 'desktop',
            'keyword' => 'shoes',
            'language_code' => 'en',
            'location_code' => 2840,
        ]);
        $b = PaidRequestFingerprint::make('dataforseo', 'serp', 'serp/google/organic/live/advanced', [
            'location_code' => 2840,
            'language_code' => 'en',
            'keyword' => 'shoes',
            'device' => 'desktop',
        ]);

        $this->assertSame($a, $b);
    }

    public function test_credentials_are_not_fingerprint_inputs(): void
    {
        $withSecretA = PaidRequestFingerprint::make('dataforseo', 'serp', 'endpoint', [
            'keyword' => 'shoes',
            'password' => 'secret-a',
            'login' => 'login-a',
            'authorization' => 'Basic abc',
        ]);
        $withSecretB = PaidRequestFingerprint::make('dataforseo', 'serp', 'endpoint', [
            'keyword' => 'shoes',
            'password' => 'secret-b',
            'login' => 'login-b',
            'authorization' => 'Basic xyz',
        ]);

        $this->assertSame($withSecretA, $withSecretB);
    }

    public function test_failed_evidence_is_not_reusable(): void
    {
        $fingerprint = PaidRequestFingerprint::make('dataforseo', 'serp', 'endpoint', [
            'keyword' => 'shoes',
        ]);

        $asset = DigitalAsset::factory()->create();
        $failedRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'failed',
            'metadata' => ['error' => 'provider failure'],
        ]);

        Evidence::factory()->create([
            'run_id' => $failedRun->id,
            'digital_asset_id' => $asset->id,
            'request_fingerprint' => $fingerprint,
            'payload' => ['ok' => false],
            'fresh_until' => now()->addDay(),
            'observed_at' => now(),
        ]);

        $completedButFailedPayload = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
            'metadata' => ['probe_ok' => false],
        ]);

        Evidence::factory()->create([
            'run_id' => $completedButFailedPayload->id,
            'digital_asset_id' => $asset->id,
            'request_fingerprint' => $fingerprint,
            'payload' => ['ok' => false],
            'fresh_until' => now()->addDay(),
            'observed_at' => now(),
        ]);

        $result = app(EvidenceFreshnessGuard::class)->evaluate($fingerprint);
        $this->assertSame(EvidenceFreshnessDecision::Miss, $result['decision']);
    }

    public function test_ambiguous_paid_post_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('Connection timed out after sending request');
        });

        try {
            app(DataForSeoApiClient::class)->request(
                $this->integration->fresh(['providerCredential']),
                'POST',
                DataForSeoEndpointAllowlist::APPENDIX_USER_DATA,
                DataForSeoApiClient::CHARGE_CLASS_PAID_CREATE,
                [['keyword' => 'shoes']],
            );
            $this->fail('Expected DataForSeoException');
        } catch (DataForSeoException $exception) {
            $this->assertSame(DataForSeoException::KIND_AMBIGUOUS_PAID, $exception->kind);
            $this->assertStringContainsString('not retried', $exception->getMessage());
        }

        $this->assertSame(1, $attempts, 'Paid POST must not be automatically retried after an ambiguous transport failure.');
    }

    public function test_http_200_internal_error_is_not_success_for_client(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 40200,
                'status_message' => 'Payment required.',
                'cost' => 0,
                'tasks_count' => 0,
                'tasks_error' => 0,
                'tasks' => [],
            ], 200),
        ]);

        try {
            app(DataForSeoApiClient::class)->getUserData($this->integration->fresh(['providerCredential']));
            $this->fail('Expected DataForSeoException');
        } catch (DataForSeoException $exception) {
            $this->assertSame(DataForSeoException::KIND_PROVIDER_STATUS, $exception->kind);
            $this->assertSame(40200, $exception->providerStatusCode);
            $this->assertStringContainsString('billing/balance', $exception->getMessage());
        }
    }

    public function test_force_refresh_bypass_allowed(): void
    {
        $fingerprint = PaidRequestFingerprint::make('dataforseo', 'serp', 'endpoint', [
            'keyword' => 'shoes',
        ]);

        $asset = DigitalAsset::factory()->create();
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'request_fingerprint' => $fingerprint,
            'payload' => ['ok' => true],
            'fresh_until' => now()->addDay(),
        ]);

        $result = app(EvidenceFreshnessGuard::class)->evaluate($fingerprint, forceRefresh: true);
        $this->assertSame(EvidenceFreshnessDecision::BypassAllowed, $result['decision']);
        $this->assertTrue($result['decision']->allowsProviderCall());
    }

    public function test_provider_call_metadata_excludes_credentials(): void
    {
        $fingerprint = PaidRequestFingerprint::make('dataforseo', 'serp', 'endpoint', [
            'keyword' => 'shoes',
            'password' => 'should-be-stripped-from-fingerprint-input',
        ]);

        $metadata = app(EvidenceFreshnessGuard::class)->providerCallRunMetadata(
            'dataforseo',
            'serp',
            $fingerprint,
            EvidenceFreshnessDecision::Miss->value,
            [
                'reported_cost_usd' => 0.002,
                'provider_status_code' => 20000,
            ],
        );

        $encoded = json_encode($metadata);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('should-be-stripped-from-fingerprint-input', $encoded);
        $this->assertStringNotContainsString('dfs-secret-password', $encoded);
        $this->assertSame(0.002, $metadata['reported_cost_usd']);
    }
}
