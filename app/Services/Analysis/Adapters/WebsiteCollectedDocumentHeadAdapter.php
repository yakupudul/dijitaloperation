<?php

namespace App\Services\Analysis\Adapters;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Analysis\Support\CollectedFactsAnalysisResult;
use App\Services\Analysis\Support\CollectedFactsJson;
use App\Services\Analysis\Support\DigitalAssetType;
use App\Services\Findings\FindingLifecycleService;
use App\Services\WebsiteDiagnosisService;
use App\Support\Findings\RuleEvaluationResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use MoxDop\Website\Diagnosis\DocumentHeadEvaluator;
use MoxDop\Website\Discovery\PublicUrlNormalizer;

/**
 * Website Data Pool snapshots → existing Document Head evaluator.
 * Does not fetch HTTP. Does not invent charset/viewport/OG when those snapshots were not collected.
 */
final class WebsiteCollectedDocumentHeadAdapter
{
    public const string PIPELINE = 'collected_facts_analysis';

    public function __construct(
        private readonly DocumentHeadEvaluator $documentHeadEvaluator,
        private readonly FindingLifecycleService $lifecycle,
        private readonly WordPressCollectedFactsEvaluator $wordpress,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    public function evaluate(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        $wordpress = $this->wordpress->evaluate($asset);
        $snapshot = $this->latestMetadataSnapshot($asset);
        if ($snapshot === null) {
            if ($wordpress['evaluated']) {
                return $this->evaluateWordPressOnly($asset, $wordpress);
            }

            return $this->skipUnprovenHomepage($asset);
        }

        $metadata = CollectedFactsJson::decode($snapshot->metadata ?? null);
        $dimensions = [];
        if (array_key_exists('title_present', $metadata) || array_key_exists('title', $metadata)) {
            $dimensions[] = 'title';
        }
        if (array_key_exists('meta_description_present', $metadata) || array_key_exists('meta_description', $metadata)) {
            $dimensions[] = 'meta_description';
        }
        if (array_key_exists('meta_robots', $metadata)) {
            $dimensions[] = 'robots';
        }

        $schema = $this->matchingSchemaSnapshot($asset, (string) $snapshot->url, (string) $snapshot->observed_at);
        $schemaMeta = $schema !== null ? CollectedFactsJson::decode($schema->metadata ?? null) : [];
        if ($schema !== null && (array_key_exists('malformed_count', $schemaMeta) || array_key_exists('block_count', $schemaMeta))) {
            $dimensions[] = 'jsonld';
        }

        if ($dimensions === []) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::Website,
                'website_snapshot_dimensions_uncollected',
                $this->provenanceFromRow($snapshot, $schema),
            );
        }

        $observedAt = CarbonImmutable::parse((string) $snapshot->observed_at);
        $run = $this->openRun($asset, $snapshot, $schema, $dimensions);

        $payload = $this->pageHtmlPayload($snapshot, $metadata, $schemaMeta);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => WebsiteDiagnosisService::MODULE_ID,
            'type' => WebsiteDiagnosisService::EVIDENCE_TYPE_PAGE_HTML,
            'title' => 'Collected website metadata snapshot',
            'collection_run_id' => $snapshot->last_collection_run_id,
            'generated_by_ai' => false,
            'payload' => $payload,
            'observed_at' => $observedAt,
        ]);

        $result = $this->documentHeadEvaluator->evaluate(
            $asset,
            $run,
            $payload,
            $observedAt,
            $dimensions,
        );

        if ($wordpress['evaluated']) {
            $this->recordWordPressEvidence($asset, $run, $wordpress);
            $wordpressObservedAt = $wordpress['observed_at'];
            $result = new RuleEvaluationResult(
                asset: $result->asset,
                sourceModule: $result->sourceModule,
                run: $result->run,
                evaluationSuccessful: $result->evaluationSuccessful,
                evaluatedRuleIds: array_values(array_unique(array_merge(
                    $result->evaluatedRuleIds,
                    $wordpress['evaluated_rule_ids'],
                ))),
                matches: array_merge($result->matches, $wordpress['matches']),
                observedAt: $wordpressObservedAt instanceof CarbonImmutable && $wordpressObservedAt->greaterThan($observedAt)
                    ? $wordpressObservedAt
                    : $observedAt,
            );
        }

        return $this->persist($asset, $run, $result, array_merge(
            $this->provenanceFromRow($snapshot, $schema),
            $wordpress['evaluated'] ? ['wordpress_connector' => $wordpress['provenance']] : [],
        ));
    }

    /**
     * @param array{
     *   evaluated:bool,evaluated_rule_ids:list<string>,matches:list<\App\Support\Findings\RuleMatch>,
     *   observed_at:?CarbonImmutable,provenance:array<string,mixed>,evidence:array<string,mixed>
     * } $wordpress
     */
    private function evaluateWordPressOnly(DigitalAsset $asset, array $wordpress): CollectedFactsAnalysisResult
    {
        $observedAt = $wordpress['observed_at'];
        if (! $observedAt instanceof CarbonImmutable) {
            return $this->skipUnprovenHomepage($asset);
        }

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => WebsiteDiagnosisService::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'pipeline' => self::PIPELINE,
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
                'dataset_id' => 'website_cms_site_snapshot',
                'collection_run_id' => $wordpress['provenance']['collection_run_id'] ?? null,
                'dataset_run_id' => $wordpress['provenance']['dataset_run_id'] ?? null,
            ],
        ]);
        $this->recordWordPressEvidence($asset, $run, $wordpress);
        $result = new RuleEvaluationResult(
            asset: $asset,
            sourceModule: WebsiteDiagnosisService::MODULE_ID,
            run: $run,
            evaluationSuccessful: true,
            evaluatedRuleIds: $wordpress['evaluated_rule_ids'],
            matches: $wordpress['matches'],
            observedAt: $observedAt,
        );

        return $this->persist($asset, $run, $result, $wordpress['provenance']);
    }

    /**
     * @param array{
     *   evaluated:bool,evaluated_rule_ids:list<string>,matches:list<\App\Support\Findings\RuleMatch>,
     *   observed_at:?CarbonImmutable,provenance:array<string,mixed>,evidence:array<string,mixed>
     * } $wordpress
     */
    private function recordWordPressEvidence(DigitalAsset $asset, Run $run, array $wordpress): void
    {
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => WebsiteDiagnosisService::MODULE_ID,
            'type' => 'website_wordpress_connector_snapshot',
            'title' => 'Collected WordPress Connector snapshot',
            'collection_run_id' => $wordpress['provenance']['collection_run_id'] ?? null,
            'generated_by_ai' => false,
            'payload' => $wordpress['evidence'],
            'observed_at' => $wordpress['observed_at'],
        ]);
    }

    /**
     * @return object{
     *     url: string,
     *     observed_at: mixed,
     *     metadata: mixed,
     *     last_collection_run_id: mixed,
     *     last_dataset_run_id: mixed,
     *     digital_asset_id: mixed
     * }|null
     */
    private function latestMetadataSnapshot(DigitalAsset $asset): ?object
    {
        $homepageUrls = $this->provenHomepageUrls($asset);
        if ($homepageUrls === []) {
            return null;
        }

        return $this->constrainCompletedWebsiteQuery(
            DB::table('website_metadata_snapshot')->where('digital_asset_id', $asset->id),
            $asset,
        )
            ->whereIn('url', $homepageUrls)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function skipUnprovenHomepage(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        $hasSnapshots = DB::table('website_metadata_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->exists();

        return CollectedFactsAnalysisResult::skipped(
            DigitalAssetType::Website,
            $hasSnapshots ? 'unproven_website_homepage_snapshot' : 'missing_website_metadata_snapshot',
            ['digital_asset_id' => $asset->id, 'dataset_id' => 'website_metadata_snapshot'],
        );
    }

    /**
     * Primary URL identities plus HTTP redirect final URLs for the homepage request.
     * Sibling crawled pages are never homepage candidates.
     *
     * @return list<string>
     */
    private function provenHomepageUrls(DigitalAsset $asset): array
    {
        $primary = is_string($asset->primary_url) ? trim($asset->primary_url) : '';
        if ($primary === '') {
            return [];
        }

        $candidates = $this->urlVariants($primary);
        $normalizedSeed = $this->urls->normalizeAbsolute($primary);
        if (is_string($normalizedSeed) && $normalizedSeed !== '') {
            $candidates = array_merge($candidates, $this->urlVariants($normalizedSeed));
        }

        $requestKeys = [];
        foreach ($candidates as $candidate) {
            $requestKeys[$this->urlMatchKey($candidate)] = true;
        }

        $httpRows = $this->constrainCompletedWebsiteQuery(
            DB::table('website_http_snapshot')->where('digital_asset_id', $asset->id),
            $asset,
        )
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get(['url', 'metadata']);

        foreach ($httpRows as $row) {
            $meta = CollectedFactsJson::decode($row->metadata ?? null);
            $requested = is_string($meta['requested_url'] ?? null) && trim((string) $meta['requested_url']) !== ''
                ? trim((string) $meta['requested_url'])
                : trim((string) $row->url);
            if ($requested === '' || ! isset($requestKeys[$this->urlMatchKey($requested)])) {
                continue;
            }

            $final = is_string($meta['final_url'] ?? null) ? trim((string) $meta['final_url']) : '';
            if ($final !== '') {
                $candidates = array_merge($candidates, $this->urlVariants($final));
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function urlVariants(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $normalized = rtrim($url, '/');
        $variants = [$url, $normalized, $normalized.'/'];
        $absolute = $this->urls->normalizeAbsolute($url);
        if (is_string($absolute) && $absolute !== '') {
            $variants[] = $absolute;
            $variants[] = rtrim($absolute, '/');
            $variants[] = rtrim($absolute, '/').'/';
        }

        return array_values(array_unique(array_filter($variants, static fn (string $value): bool => $value !== '')));
    }

    private function urlMatchKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim(trim($url), '/'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '/' ? '' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    private function matchingSchemaSnapshot(DigitalAsset $asset, string $url, string $observedAt): ?object
    {
        return $this->constrainCompletedWebsiteQuery(
            DB::table('website_schema_snapshot')->where('digital_asset_id', $asset->id),
            $asset,
        )
            ->where('url', $url)
            ->where('observed_at', $observedAt)
            ->first();
    }

    private function constrainCompletedWebsiteQuery(Builder $query, DigitalAsset $asset): Builder
    {
        $ids = $this->completedWebsiteDatasetRunIds($asset);
        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('last_dataset_run_id', $ids);
    }

    /**
     * @return list<int>
     */
    private function completedWebsiteDatasetRunIds(DigitalAsset $asset): array
    {
        return CollectionDatasetRun::query()
            ->where('status', CollectionRunStatus::Completed)
            ->whereHas('resourceRun', function (EloquentBuilder $resourceRun) use ($asset): void {
                $resourceRun
                    ->where('digital_asset_id', $asset->id)
                    ->whereNull('external_resource_id');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $schemaMeta
     * @return array<string, mixed>
     */
    private function pageHtmlPayload(object $snapshot, array $metadata, array $schemaMeta): array
    {
        $title = is_string($metadata['title'] ?? null) ? $metadata['title'] : null;
        $titlePresent = array_key_exists('title_present', $metadata)
            ? (bool) $metadata['title_present']
            : ($title !== null);
        $description = is_string($metadata['meta_description'] ?? null) ? $metadata['meta_description'] : null;
        $descriptionPresent = array_key_exists('meta_description_present', $metadata)
            ? (bool) $metadata['meta_description_present']
            : ($description !== null);
        $robots = is_string($metadata['meta_robots'] ?? null) ? $metadata['meta_robots'] : null;
        $directives = [];
        if (is_string($robots) && $robots !== '') {
            $directives = array_values(array_filter(array_map(
                static fn (string $part): string => strtolower(trim($part)),
                preg_split('/[,\s]+/', $robots) ?: [],
            )));
        }

        return [
            'url' => (string) $snapshot->url,
            'response_ok' => true,
            'generated_by_ai' => false,
            'document' => [
                'title' => $title,
                'title_present' => $titlePresent,
                'title_empty' => $titlePresent && ($title === null || trim($title) === ''),
                'title_length' => is_string($title) ? mb_strlen($title) : null,
            ],
            'meta' => [
                'description_present' => $descriptionPresent,
                'description_empty' => $descriptionPresent && ($description === null || trim((string) $description) === ''),
                'description_length' => is_string($description) ? mb_strlen($description) : null,
                'robots_directives' => $directives,
                'googlebot_directives' => [],
            ],
            'open_graph' => [],
            'structured_data' => [
                'malformed_count' => is_numeric($schemaMeta['malformed_count'] ?? null)
                    ? (int) $schemaMeta['malformed_count']
                    : 0,
                'block_count' => is_numeric($schemaMeta['block_count'] ?? null)
                    ? (int) $schemaMeta['block_count']
                    : 0,
            ],
            'provenance' => $this->provenanceFromRow($snapshot, null),
        ];
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function openRun(DigitalAsset $asset, object $snapshot, ?object $schema, array $dimensions): Run
    {
        return Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => WebsiteDiagnosisService::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'pipeline' => self::PIPELINE,
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
                'collected_dimensions' => $dimensions,
                'dataset_id' => 'website_metadata_snapshot',
                'schema_dataset_id' => $schema !== null ? 'website_schema_snapshot' : null,
                'url' => $snapshot->url,
                'collection_run_id' => $snapshot->last_collection_run_id,
                'dataset_run_id' => $snapshot->last_dataset_run_id,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    private function persist(
        DigitalAsset $asset,
        Run $run,
        RuleEvaluationResult $result,
        array $provenance,
    ): CollectedFactsAnalysisResult {
        $stats = $this->lifecycle->apply($result);
        $run->status = $result->evaluationSuccessful ? 'completed' : 'partial';
        $run->finished_at = now();
        $run->metadata = array_merge($run->metadata ?? [], [
            'evaluation_successful' => $result->evaluationSuccessful,
            'evaluated_rule_ids' => $result->evaluatedRuleIds,
            'findings' => $stats,
        ]);
        $run->save();

        return CollectedFactsAnalysisResult::evaluated(
            DigitalAssetType::Website,
            $run,
            $stats,
            $result->evaluationSuccessful,
            $result->evaluatedRuleIds,
            $provenance,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function provenanceFromRow(object $snapshot, ?object $schema): array
    {
        return [
            'digital_asset_id' => (int) $snapshot->digital_asset_id,
            'dataset_id' => 'website_metadata_snapshot',
            'schema_dataset_id' => $schema !== null ? 'website_schema_snapshot' : null,
            'url' => $snapshot->url,
            'observed_at' => (string) $snapshot->observed_at,
            'collection_run_id' => $snapshot->last_collection_run_id,
            'dataset_run_id' => $snapshot->last_dataset_run_id,
            'generated_by_ai' => false,
        ];
    }
}
