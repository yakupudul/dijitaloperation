<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Enums\IntelligenceCore\BusinessActionSignalClass;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\IntelligenceCore\Identity\BusinessActionIdentityResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\InsertsFacts;

/**
 * A website bound to real-shaped Search Console and GA4 resources, with daily fact rows written the way production
 * writes them (compact store on PostgreSQL, plain rows on SQLite).
 */
trait BuildsProjectionFixture
{
    use InsertsFacts;

    private const string SITE_URL = 'sc-domain:golden.test';

    private const string PROPERTY_ID = '123456';

    private DigitalAsset $site;

    private CoreExternalResource $gscResource;

    private CoreExternalResource $ga4Resource;

    /** @var list<array{0:int,1:int}> collection run id, dataset run id */
    private array $runs = [];

    private function bindWebsite(): DigitalAsset
    {
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $brand = Brand::factory()->create(['name' => 'Golden Klinik']);
        $this->site = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'name' => 'Golden site',
            'domain' => 'golden.test', 'primary_url' => 'https://golden.test/', 'languages' => ['tr'], 'target_countries' => ['TR'],
            'seo_market_location_code' => 2792, 'seo_market_language_code' => 'tr',
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY, GoogleScopes::ANALYTICS_READONLY]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY.' '.GoogleScopes::ANALYTICS_READONLY],
            'expires_at' => now()->addYear(),
        ]);
        $this->gscResource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => self::SITE_URL, 'display_name' => 'Golden GSC', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'America/Los_Angeles'],
        ]);
        $this->ga4Resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/'.self::PROPERTY_ID, 'display_name' => 'Golden GA4', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Istanbul'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gscResource->id,
            'capability' => GscSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->ga4Resource->id,
            'capability' => Ga4SpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $datasetRun = CollectionDatasetRun::factory()->create();
            $this->runs[] = [(int) $datasetRun->collection_run_id, (int) $datasetRun->id];
        }

        return $this->site;
    }

    /** @param array<string, string> $dimensions */
    private function gsc(string $table, string $date, array $dimensions, int $clicks, int $impressions, ?float $position, int $run, string $collectedAt, bool $nullMetadata = false, string $searchType = 'web'): void
    {
        $this->insertFact($table, [
            'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gscResource->id, 'site_url' => self::SITE_URL,
            'reporting_date' => $date, ...$dimensions, 'clicks' => $clicks, 'impressions' => $impressions, 'contract_version' => 1,
            'last_collection_run_id' => $this->runs[$run][0], 'last_dataset_run_id' => $this->runs[$run][1],
            'first_collected_at' => $collectedAt, 'last_collected_at' => $collectedAt, 'source_timezone' => 'America/Los_Angeles',
            'record_fingerprint' => hash('sha256', $table.$date.implode('|', $dimensions).$searchType),
            'metadata' => $nullMetadata ? null : json_encode(['provider_average_position' => $position]),
            'created_at' => $collectedAt, 'updated_at' => $collectedAt, 'search_type' => $searchType,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function ga4(string $table, string $date, array $values, int $run, string $collectedAt): void
    {
        $this->insertFact($table, [
            'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->ga4Resource->id, 'property_id' => self::PROPERTY_ID,
            'reporting_date' => $date, ...$values, 'contract_version' => 1,
            'last_collection_run_id' => $this->runs[$run][0], 'last_dataset_run_id' => $this->runs[$run][1],
            'first_collected_at' => $collectedAt, 'last_collected_at' => $collectedAt, 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $table.$date.json_encode($values)), 'metadata' => null,
            'created_at' => $collectedAt, 'updated_at' => $collectedAt,
        ]);
    }

    private function websiteUrl(string $url, string $collectedAt): void
    {
        DB::table('website_url')->insert([
            'digital_asset_id' => $this->site->id, 'asset_id' => (string) $this->site->id, 'normalized_url' => $url,
            'contract_version' => 1, 'first_collected_at' => $collectedAt, 'last_collected_at' => $collectedAt,
            'record_fingerprint' => hash('sha256', $url), 'created_at' => $collectedAt, 'updated_at' => $collectedAt,
        ]);
    }

    private function mapLeadOutcome(): void
    {
        $resolver = app(BusinessActionIdentityResolver::class);
        $action = $resolver->define($this->site->brand, 'randevu', 'lead', 'Randevu');
        $resolver->mapSignal(
            action: $action,
            observedName: 'generate_lead',
            signalClass: BusinessActionSignalClass::AnalyticsEvent,
            source: new IntelligenceSourceReference(
                providerOrSource: 'ga4', sourceClass: IntelligenceSourceClass::ProviderAttributed, sourceSemantic: 'analytics_key_event',
                datasetId: 'ga4_key_event_daily', sourceRecordKey: 'generate_lead', externalResourceId: (int) $this->ga4Resource->id,
            ),
            time: new IntelligenceTimeContext(sourceTimezone: 'Europe/Istanbul'),
            providerActionId: 'generate_lead',
        );
    }
}
