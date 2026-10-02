<?php

namespace Tests\Feature;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Integrations\ResourceAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\GoogleBusinessProfile\Workspace\OperatorGbpWorkspace;
use Tests\TestCase;

/**
 * A Business Profile run whose core data arrived but some optional datasets did not (API not enabled, reviews
 * access) keeps the account current; a run without the core data is a failure.
 */
final class GbpPartialCollectionTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array<string, string>>  $datasets */
    private function collectWith(array $datasets, string $status): ResourceAutomation
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/1']);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $resource->integration_id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $resource->integration_id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/business.manage'], 'expires_at' => now()->addHour()]);
        $asset = DigitalAsset::factory()->create(['type' => 'google_business_profile', 'status' => 'active']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true, 'collection_status' => 'planning']);
        app()->instance(GoogleBusinessProfileBoundCollector::class, new class($datasets, $status)
        {
            public function __construct(private array $datasets, private string $status) {}

            public function collectResourceStep(CoreExternalResource $resource, Run $run): Run
            {
                $run->update(['status' => $this->status, 'metadata' => array_merge($run->metadata ?? [], ['datasets' => $this->datasets])]);

                return $run->fresh();
            }
        });
        app(ResourceAutomationService::class)->collect($automation->id);

        return $automation->fresh();
    }

    public function test_partial_run_with_core_data_stays_current(): void
    {
        $automation = $this->collectWith([
            'gbp_location' => ['status' => 'available'], 'gbp_performance_daily' => ['status' => 'partial'],
            'gbp_reviews' => ['status' => 'unavailable', 'reason' => 'gbp_reviews provider request failed with HTTP 403. PERMISSION_DENIED'],
        ], 'partial');

        $this->assertSame('current', $automation->collection_status);
        $this->assertNull($automation->collection_error);
    }

    public function test_run_without_core_data_fails(): void
    {
        $automation = $this->collectWith(['gbp_location' => ['status' => 'unavailable'], 'gbp_performance_daily' => ['status' => 'available']], 'partial');

        $this->assertSame('attention', $automation->collection_status);
        $this->assertSame('collection_failed', $automation->collection_error);
    }

    public function test_google_errors_get_a_plain_hint(): void
    {
        $this->assertStringContainsString('API Kitaplığı', OperatorGbpWorkspace::errorHint('gbp_place_actions: HTTP 403. PERMISSION_DENIED SERVICE_DISABLED'));
        $this->assertStringContainsString('Yetki yok', OperatorGbpWorkspace::errorHint('gbp_reviews provider request failed with HTTP 403.'));
        $this->assertNull(OperatorGbpWorkspace::errorHint(''));
    }
}
