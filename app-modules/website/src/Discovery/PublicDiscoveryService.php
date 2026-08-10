<?php

namespace MoxDop\Website\Discovery;

use App\Models\BrandIntelligenceContext;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Evidence;
use App\Models\Run;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Operator-triggered bounded public Website Discovery orchestration.
 * Creates canonical Run + Discovery Evidence + review candidates.
 * Does not overwrite Brand Context.
 */
final class PublicDiscoveryService
{
    public function __construct(
        private readonly PublicSiteCrawler $crawler = new PublicSiteCrawler,
        private readonly DiscoveryCandidateBuilder $builder = new DiscoveryCandidateBuilder,
        private readonly CompetitorDomainCollector $competitors = new CompetitorDomainCollector,
        private readonly DiscoveryInferenceService $inferences = new DiscoveryInferenceService,
    ) {}

    /**
     * @return array{
     *     run: Run,
     *     status: string,
     *     message: string,
     *     pages_inspected: int,
     *     fact_candidates: int,
     *     inference_candidates: int,
     *     competitor_candidates: int,
     *     evidence_ids: list<int>
     * }
     */
    public function discover(DigitalAsset $asset): array
    {
        if ($asset->type !== 'website') {
            throw new InvalidArgumentException('Public discovery requires a Website Digital Asset.');
        }

        $seedUrl = $this->seedUrl($asset);
        if ($seedUrl === null) {
            throw new InvalidArgumentException('Website primary URL or domain is required for public discovery.');
        }

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'core_asset_binding_id' => null,
            'module_id' => DiscoveryConfig::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'manual',
                'human_title' => 'Public discovery',
                'discovery_mode' => DiscoveryConfig::VERSION,
                'seed_url' => $seedUrl,
                'brand_id' => $asset->brand_id,
            ],
        ]);

        $evidenceIds = [];
        $observedAt = now();

        try {
            $crawl = $this->crawler->crawl($seedUrl);

            foreach ($crawl['pages'] as $page) {
                $extracted = is_array($page['extracted'] ?? null) ? $page['extracted'] : [];
                $evidence = Evidence::query()->create([
                    'run_id' => $run->id,
                    'digital_asset_id' => $asset->id,
                    'source_module' => DiscoveryConfig::MODULE_ID,
                    'type' => DiscoveryConfig::EVIDENCE_PAGE_SNAPSHOT,
                    'title' => 'Public page: '.($extracted['title'] ?? $page['final_url'] ?? 'page'),
                    'payload' => [
                        'ok' => true,
                        'requested_url' => $page['requested_url'] ?? null,
                        'source_url' => $page['final_url'] ?? null,
                        'status_code' => $page['status_code'] ?? null,
                        'content_type' => $page['content_type'] ?? null,
                        'bytes' => $page['bytes'] ?? null,
                        'redirect_count' => $page['redirect_count'] ?? null,
                        'retrieved_at' => $observedAt->toIso8601String(),
                        'normalization_version' => DiscoveryConfig::VERSION,
                        'extracted' => $extracted,
                    ],
                    'observed_at' => $observedAt,
                ]);
                $evidenceIds[] = (int) $evidence->id;
            }

            $competitorResult = $this->competitors->collect($asset, $run, $observedAt);
            if ($competitorResult['evidence'] instanceof Evidence) {
                $evidenceIds[] = (int) $competitorResult['evidence']->id;
            }

            $summaryPayload = [
                'ok' => $crawl['status'] !== 'failed',
                'status' => $crawl['status'],
                'seed_url' => $crawl['seed_url'],
                'pages_inspected' => $crawl['pages_inspected'],
                'total_bytes' => $crawl['total_bytes'],
                'failures' => $crawl['failures'],
                'page_urls' => array_values(array_filter(array_map(
                    fn (array $page): ?string => isset($page['final_url']) && is_string($page['final_url']) ? $page['final_url'] : null,
                    $crawl['pages'],
                ))),
                'retrieved_at' => $observedAt->toIso8601String(),
                'normalization_version' => DiscoveryConfig::VERSION,
                'competitor_status' => $competitorResult['status'],
                'competitor_message' => $competitorResult['message'],
                'competitor_provider' => $competitorResult['provider'],
                'competitor_count' => count($competitorResult['competitors']),
            ];

            $summary = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => DiscoveryConfig::MODULE_ID,
                'type' => DiscoveryConfig::EVIDENCE_SITE_SUMMARY,
                'title' => 'Public Website discovery summary',
                'payload' => $summaryPayload,
                'observed_at' => $observedAt,
            ]);
            $evidenceIds[] = (int) $summary->id;

            $factRows = $this->builder->fromCrawl($crawl);
            $competitorRows = $this->builder->fromCompetitors(
                $competitorResult['competitors'],
                (string) ($competitorResult['provider'] ?? 'dataforseo'),
                $competitorResult['query_note'],
            );

            $inferenceRows = [];
            $aiMeta = [
                'attempted' => false,
                'ok' => false,
                'message' => null,
                'provider' => null,
                'model' => null,
                'route_key' => null,
            ];

            if ($crawl['pages'] !== []) {
                try {
                    $ai = $this->inferences->propose($asset, $summaryPayload, $factRows);
                    $aiMeta = $ai['meta'];
                    $inferenceRows = $this->builder->inferencesFromAi($ai['rows']);
                } catch (Throwable $exception) {
                    Log::warning('website_discovery_inference_skipped', [
                        'digital_asset_id' => $asset->id,
                        'run_id' => $run->id,
                        'error_class' => class_basename($exception),
                    ]);
                    $aiMeta['message'] = 'AI inferences skipped: '.class_basename($exception);
                }
            }

            $primaryEvidenceId = (int) $summary->id;
            $facts = $this->persistCandidates($asset, $run, $primaryEvidenceId, $factRows);
            $competitorsPersisted = $this->persistCandidates($asset, $run, $competitorResult['evidence']?->id ?? $primaryEvidenceId, $competitorRows);
            $inferencesPersisted = $this->persistCandidates($asset, $run, $primaryEvidenceId, $inferenceRows);

            $runStatus = match ($crawl['status']) {
                'succeeded' => 'completed',
                'partial' => 'completed',
                default => 'failed',
            };

            // Partial crawl still completes the Run with partial semantics in metadata.
            if ($crawl['status'] === 'partial') {
                $runStatus = 'completed';
            }

            $run->update([
                'status' => $runStatus,
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'discovery_status' => $crawl['status'],
                    'pages_inspected' => $crawl['pages_inspected'],
                    'total_bytes' => $crawl['total_bytes'],
                    'failure_count' => count($crawl['failures']),
                    'evidence_ids' => $evidenceIds,
                    'fact_candidates' => $facts,
                    'inference_candidates' => $inferencesPersisted,
                    'competitor_candidates' => $competitorsPersisted,
                    'competitor_status' => $competitorResult['status'],
                    'competitor_message' => $competitorResult['message'],
                    'ai' => $aiMeta,
                ]),
            ]);

            $message = match ($crawl['status']) {
                'succeeded' => 'Public discovery completed.',
                'partial' => 'Public discovery completed with partial page retrieval.',
                default => 'Public discovery could not retrieve public Website pages.',
            };

            return [
                'run' => $run->fresh(['evidence']) ?? $run,
                'status' => $crawl['status'],
                'message' => $message,
                'pages_inspected' => $crawl['pages_inspected'],
                'fact_candidates' => $facts,
                'inference_candidates' => $inferencesPersisted,
                'competitor_candidates' => $competitorsPersisted,
                'evidence_ids' => $evidenceIds,
            ];
        } catch (Throwable $exception) {
            Log::warning('website_public_discovery_failed', [
                'digital_asset_id' => $asset->id,
                'run_id' => $run->id,
                'error_class' => class_basename($exception),
            ]);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'discovery_status' => 'failed',
                    'error_class' => class_basename($exception),
                    'evidence_ids' => $evidenceIds,
                ]),
            ]);

            throw $exception;
        }
    }

    private function seedUrl(DigitalAsset $asset): ?string
    {
        foreach ([$asset->primary_url, $asset->domain] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $value = trim($candidate);
            if (! str_contains($value, '://')) {
                $value = 'https://'.$value;
            }
            $normalized = (new PublicUrlNormalizer)->normalizeAbsolute($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistCandidates(DigitalAsset $asset, Run $run, int $evidenceId, array $rows): int
    {
        $createdOrKeptPending = 0;
        $context = BrandIntelligenceContext::query()->where('brand_id', $asset->brand_id)->first();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['candidate_kind'] ?? '');
            $type = (string) ($row['candidate_type'] ?? '');
            $field = (string) ($row['target_field'] ?? '');
            $value = trim((string) ($row['proposed_value'] ?? ''));
            if ($kind === '' || $type === '' || $field === '' || $value === '') {
                continue;
            }

            $support = is_array($row['support_json'] ?? null) ? $row['support_json'] : [];
            $sourceIdentity = (string) ($support['source_url'] ?? $support['domain'] ?? $support['provider'] ?? 'discovery');

            $fingerprint = hash('sha256', implode('|', [
                (string) $asset->brand_id,
                (string) $asset->id,
                $kind,
                $type,
                $field,
                mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value)),
                mb_strtolower($sourceIdentity),
            ]));

            $existing = DiscoveryCandidate::query()
                ->where('digital_asset_id', $asset->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing instanceof DiscoveryCandidate) {
                if (in_array($existing->status, [DiscoveryCandidate::STATUS_ACCEPTED, DiscoveryCandidate::STATUS_IGNORED], true)) {
                    continue;
                }

                $existing->forceFill([
                    'run_id' => $run->id,
                    'evidence_id' => $evidenceId,
                    'support_json' => $support,
                    'support_label' => $row['support_label'] ?? $existing->support_label,
                    'proposed_value' => $value,
                ])->save();
                $createdOrKeptPending++;

                continue;
            }

            $conflictSupport = $this->conflictSupport($context, $field, $value, $support);
            if ($conflictSupport !== null) {
                $support = $conflictSupport;
                $type = $type === 'competitor' ? $type : 'conflict';
            }

            DiscoveryCandidate::query()->create([
                'brand_id' => $asset->brand_id,
                'digital_asset_id' => $asset->id,
                'run_id' => $run->id,
                'evidence_id' => $evidenceId,
                'fingerprint' => $fingerprint,
                'candidate_kind' => $kind,
                'candidate_type' => $type,
                'target_field' => $field,
                'proposed_value' => $value,
                'support_json' => $support,
                'support_label' => $row['support_label'] ?? 'moderate',
                'status' => DiscoveryCandidate::STATUS_PENDING,
            ]);
            $createdOrKeptPending++;
        }

        return $createdOrKeptPending;
    }

    /**
     * @param  array<string, mixed>  $support
     * @return array<string, mixed>|null
     */
    private function conflictSupport(?BrandIntelligenceContext $context, string $field, string $value, array $support): ?array
    {
        if (! $context instanceof BrandIntelligenceContext) {
            return null;
        }

        if (! in_array($field, ['business_summary', 'positioning'], true)) {
            return null;
        }

        $current = is_string($context->{$field} ?? null) ? trim((string) $context->{$field}) : '';
        if ($current === '') {
            return null;
        }

        $normalize = fn (string $v): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v) ?? $v));
        if ($normalize($current) === $normalize($value)) {
            return null;
        }

        $support['conflict_with_existing'] = $current;
        $support['conflict_note'] = 'Existing Brand Context differs. Human override wins until Accept decides.';

        return $support;
    }
}
