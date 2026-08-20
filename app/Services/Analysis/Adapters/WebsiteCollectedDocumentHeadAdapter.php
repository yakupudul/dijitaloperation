<?php

namespace App\Services\Analysis\Adapters;

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
use Illuminate\Support\Facades\DB;
use MoxDop\Website\Diagnosis\DocumentHeadEvaluator;

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
    ) {}

    public function evaluate(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        $snapshot = $this->latestMetadataSnapshot($asset);
        if ($snapshot === null) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::Website,
                'missing_website_metadata_snapshot',
                ['digital_asset_id' => $asset->id, 'dataset_id' => 'website_metadata_snapshot'],
            );
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

        return $this->persist($asset, $run, $result, $this->provenanceFromRow($snapshot, $schema));
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
        $query = DB::table('website_metadata_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id');

        $primary = is_string($asset->primary_url) ? trim($asset->primary_url) : '';
        if ($primary !== '') {
            $normalized = rtrim($primary, '/');
            $preferred = (clone $query)
                ->where(function ($inner) use ($primary, $normalized): void {
                    $inner->where('url', $primary)
                        ->orWhere('url', $normalized)
                        ->orWhere('url', $normalized.'/');
                })
                ->first();
            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $query->first();
    }

    private function matchingSchemaSnapshot(DigitalAsset $asset, string $url, string $observedAt): ?object
    {
        return DB::table('website_schema_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->where('url', $url)
            ->where('observed_at', $observedAt)
            ->first();
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
