<?php

namespace Tests\Feature\Collection;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\Providers\DataForSeo\DataForSeoDatasetExecutor;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\CollectionScheduler\CollectionSchedulingPolicyRegistry;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebsiteDataForSeoContractRuntimeClosureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const WEBSITE_KINDS = [
        'http_html_diagnosis',
        'pagespeed',
        'dns_tls',
        'public_crawl',
    ];

    /**
     * @var list<string>
     */
    private const DFS_KINDS = [
        'free_user',
        'free_markets',
        'ranked_keywords',
        'keywords_for_site',
        'competitors_domain',
    ];

    #[Test]
    public function every_collection_ready_website_family_is_wired_to_executor_storage_and_freshness(): void
    {
        $registry = app(DataContractRegistryLoader::class);
        $registry->load();
        $storage = app(DataPoolStorageRegistry::class);
        $freshness = app(DataFreshnessPolicyLoader::class);
        $executor = app(WebsiteDatasetExecutor::class);
        $resolver = app(DatasetExecutorResolver::class);

        $readyFamilies = collect($registry->requestFamilies())
            ->filter(static fn (array $family): bool => in_array((string) ($family['provider_or_source'] ?? ''), [
                'WEBSITE_DIRECT',
                'DOMAIN_DNS_TLS',
                'PAGESPEED_TECHNICAL',
            ], true)
                && ! in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true))
            ->values();

        $readyIds = $readyFamilies->pluck('id')->all();
        $this->assertEqualsCanonicalizing(WebsiteRequestFamilyCatalog::supportedFamilies(), array_values($readyIds));
        $this->assertNotContains(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $readyIds);

        $deferred = collect($registry->requestFamilies())
            ->first(static fn (array $family): bool => ($family['id'] ?? '') === WebsiteRequestFamilyCatalog::FAMILY_WP_REST);
        $this->assertNotNull($deferred);
        $this->assertSame('DEFERRED', $deferred['status'] ?? null);

        $executorSource = file_get_contents(app_path('Services/Collection/Providers/Website/WebsiteDatasetExecutor.php'));
        $this->assertIsString($executorSource);
        $this->assertStringNotContainsString('DB::table(', $executorSource);
        $this->assertStringContainsString('DatasetWritePipeline', $executorSource);

        foreach ($readyFamilies as $family) {
            $familyId = (string) $family['id'];
            $this->assertContains($familyId, $executor->supportedRequestFamilies(), $familyId.' must be registered on WebsiteDatasetExecutor');

            $resolved = $resolver->resolve(CollectionDatasetRun::factory()->make([
                'request_family_id' => $familyId,
                'provider_or_source' => (string) $family['provider_or_source'],
            ]));
            $this->assertInstanceOf(WebsiteDatasetExecutor::class, $resolved);

            $definition = WebsiteRequestFamilyCatalog::definition($familyId);
            $this->assertContains($definition['kind'], self::WEBSITE_KINDS, $familyId.' kind must have an executor arm');
            $this->assertStringContainsString("'".$definition['kind']."' =>", $executorSource);
            $this->assertNotEmpty($definition['dataset_ids']);

            foreach ($definition['dataset_ids'] as $datasetId) {
                $dataset = $registry->dataset($datasetId);
                $this->assertNotNull($dataset, $datasetId.' must exist in the data contract registry');
                $this->assertSame('COLLECTION_READY', $dataset['status'] ?? null);
                $this->assertTrue($storage->hasPhysicalTable($datasetId), $datasetId.' must map to PHYSICAL_TABLE');

                $physical = $storage->physicalDataset($datasetId);
                $this->assertSame($datasetId, $physical['table']);
                $disposition = $storage->disposition($datasetId);
                $this->assertSame('PHYSICAL_TABLE', $disposition['disposition'] ?? null);
                $this->assertNotEmpty($physical['natural_key']);
                $this->assertContains('digital_asset_id', $physical['natural_key']);

                $policy = $freshness->policy($datasetId);
                $this->assertNotNull($policy, $datasetId.' must have a freshness/backfill policy');
                $this->assertFalse($policy['incremental_applicable']);
                $this->assertSame('CONTROLLED_ON_DEMAND', $policy['collection_mode'] ?? null);
                $this->assertNotEmpty($policy['non_applicable_reason'] ?? null);
            }
        }
    }

    #[Test]
    public function every_collection_ready_dataforseo_family_is_wired_and_paid_families_are_physical(): void
    {
        $registry = app(DataContractRegistryLoader::class);
        $registry->load();
        $storage = app(DataPoolStorageRegistry::class);
        $freshness = app(DataFreshnessPolicyLoader::class);
        $executor = app(DataForSeoDatasetExecutor::class);
        $resolver = app(DatasetExecutorResolver::class);

        $readyFamilies = collect($registry->requestFamilies())
            ->filter(static fn (array $family): bool => ($family['provider_or_source'] ?? '') === 'DATAFORSEO'
                && ! in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true))
            ->values();

        $readyIds = $readyFamilies->pluck('id')->all();
        $this->assertEqualsCanonicalizing(DataForSeoRequestFamilyCatalog::supportedFamilies(), array_values($readyIds));
        foreach (DataForSeoRequestFamilyCatalog::deferredFamilies() as $deferredId) {
            $this->assertNotContains($deferredId, $readyIds);
            $deferred = collect($registry->requestFamilies())
                ->first(static fn (array $family): bool => ($family['id'] ?? '') === $deferredId);
            $this->assertNotNull($deferred);
            $this->assertSame('DEFERRED', $deferred['status'] ?? null);
        }

        $executorSource = file_get_contents(app_path('Services/Collection/Providers/DataForSeo/DataForSeoDatasetExecutor.php'));
        $this->assertIsString($executorSource);
        $this->assertStringNotContainsString('DB::table(', $executorSource);

        $rawDisposition = $storage->disposition('dataforseo_raw_response');
        $this->assertSame('RAW_ONLY', $rawDisposition['disposition'] ?? null);
        $this->assertFalse($storage->hasPhysicalTable('dataforseo_raw_response'));

        foreach ($readyFamilies as $family) {
            $familyId = (string) $family['id'];
            $this->assertContains($familyId, $executor->supportedRequestFamilies(), $familyId.' must be registered on DataForSeoDatasetExecutor');

            $resolved = $resolver->resolve(CollectionDatasetRun::factory()->make([
                'request_family_id' => $familyId,
                'provider_or_source' => 'DATAFORSEO',
            ]));
            $this->assertInstanceOf(DataForSeoDatasetExecutor::class, $resolved);

            $definition = DataForSeoRequestFamilyCatalog::definition($familyId);
            $this->assertContains($definition['kind'], self::DFS_KINDS);
            $this->assertStringContainsString("'".$definition['kind']."' =>", $executorSource);
            $this->assertNotEmpty($definition['dataset_ids']);

            foreach ($definition['dataset_ids'] as $datasetId) {
                $dataset = $registry->dataset($datasetId);
                $this->assertNotNull($dataset, $datasetId.' must exist in the data contract registry');
                $this->assertSame('DATAFORSEO', $dataset['provider_or_source'] ?? null);
                $this->assertSame('COLLECTION_READY', $dataset['status'] ?? null);

                $policy = $freshness->policy($datasetId);
                $this->assertNotNull($policy, $datasetId.' must have a freshness policy');
                $this->assertFalse($policy['incremental_applicable']);
                $this->assertSame('CONTROLLED_ON_DEMAND', $policy['collection_mode'] ?? null);

                if ($definition['raw_only'] === true) {
                    $this->assertSame('dataforseo_raw_response', $datasetId);
                    $this->assertSame('RAW_ONLY', $storage->disposition($datasetId)['disposition'] ?? null);

                    continue;
                }

                $this->assertTrue($storage->hasPhysicalTable($datasetId), $datasetId.' must map to PHYSICAL_TABLE');
                $physical = $storage->physicalDataset($datasetId);
                $this->assertSame($datasetId, $physical['table']);
                $this->assertSame('PHYSICAL_TABLE', $storage->disposition($datasetId)['disposition'] ?? null);
                $this->assertNotEmpty($physical['natural_key']);
                $this->assertContains('retrieved_at', $physical['natural_key']);
            }
        }
    }

    #[Test]
    public function dataforseo_is_never_routinely_scheduled(): void
    {
        $registry = app(CollectionSchedulingPolicyRegistry::class);
        $this->assertFalse($registry->isDataForSeoRoutinelyScheduled());
        $this->assertNotContains('DATAFORSEO', $registry->schedulableProviders());
        $this->assertNotContains('WEBSITE_DIRECT', $registry->schedulableProviders());
        $this->assertNotContains('DOMAIN_DNS_TLS', $registry->schedulableProviders());
        $this->assertNotContains('PAGESPEED_TECHNICAL', $registry->schedulableProviders());
    }
}
