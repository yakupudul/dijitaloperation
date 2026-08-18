<?php

namespace App\Services\Prospects;

use App\Enums\ProspectEvidenceProvenance;
use App\Enums\ProspectResearchRunStatus;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\ProspectEvidence;
use App\Models\ProspectResearchRun;
use App\Models\User;
use App\Support\Prospects\ProspectResearchConfig;
use App\Support\Prospects\ProspectResearchFixtures;
use Illuminate\Support\Facades\Log;
use MoxDop\Website\Discovery\DiscoveryCandidateBuilder;
use MoxDop\Website\Discovery\PublicSiteCrawler;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

/**
 * Prospect-native public research using the canonical Website Discovery crawl stack.
 */
final class ProspectResearchService
{
    public function __construct(
        private readonly ProspectWebsiteValidator $websiteValidator = new ProspectWebsiteValidator,
        private readonly PublicSiteCrawler $crawler = new PublicSiteCrawler,
        private readonly DiscoveryCandidateBuilder $candidateBuilder = new DiscoveryCandidateBuilder,
        private readonly ProspectSalesIntelligenceService $salesIntelligence = new ProspectSalesIntelligenceService,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
        private readonly PublicUrlNormalizer $urlNormalizer = new PublicUrlNormalizer,
    ) {}

    public function queue(Prospect $prospect, ?User $actor = null): ProspectResearchRun
    {
        $run = ProspectResearchRun::query()->create([
            'prospect_id' => $prospect->id,
            'status' => ProspectResearchRunStatus::Queued,
            'seed_url' => $prospect->website_url,
            'started_at' => null,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'manual',
                'human_title' => 'Prospect research',
            ],
        ]);

        if ($prospect->status === ProspectStatus::New) {
            $prospect->update(['status' => ProspectStatus::Researching]);
        }

        $this->activities->record(
            $prospect,
            'prospect.research_started',
            __('operator.prospects.activity.research_started'),
            null,
            $actor,
            ['run_id' => $run->id],
        );

        return $run;
    }

    public function execute(ProspectResearchRun $run, ?User $actor = null): ProspectResearchRun
    {
        $prospect = $run->prospect()->firstOrFail();

        $run->update([
            'status' => ProspectResearchRunStatus::Running,
            'started_at' => $run->started_at ?? now(),
        ]);

        try {
            $seedUrl = $this->resolveSeedUrl($prospect);
            $run->update(['seed_url' => $seedUrl]);

            if ($seedUrl === null) {
                return $this->finishWithoutWebsite($run, $prospect, $actor);
            }

            $crawl = $this->crawlSeed($seedUrl);
            $evidenceIds = $this->persistObservedEvidence($prospect, $run, $crawl);
            $this->persistFactCandidates($prospect, $run, $crawl, $evidenceIds);

            $status = match ($crawl['status'] ?? 'failed') {
                'succeeded' => ProspectResearchRunStatus::Completed,
                'partial' => ProspectResearchRunStatus::Partial,
                default => ProspectResearchRunStatus::Failed,
            };

            $message = match ($status) {
                ProspectResearchRunStatus::Completed => 'Public research completed.',
                ProspectResearchRunStatus::Partial => 'Public research completed with gaps.',
                default => 'Public research failed.',
            };

            $this->salesIntelligence->generate($prospect, $run, $actor);

            return $this->finalize($run, $prospect, $status, $message, [
                'pages_inspected' => $crawl['pages_inspected'] ?? 0,
                'evidence_ids' => $evidenceIds,
                'crawl_status' => $crawl['status'] ?? null,
            ], $actor);
        } catch (Throwable $exception) {
            Log::warning('prospect_research_failed', [
                'prospect_id' => $prospect->id,
                'run_id' => $run->id,
                'error_class' => class_basename($exception),
            ]);

            $this->activities->record(
                $prospect,
                'prospect.research_failed',
                __('operator.prospects.activity.research_failed'),
                class_basename($exception),
                $actor,
                ['run_id' => $run->id],
            );

            return $this->finalize($run, $prospect, ProspectResearchRunStatus::Failed, 'Research failed safely.', [
                'error' => class_basename($exception),
            ], $actor);
        }
    }

    private function resolveSeedUrl(Prospect $prospect): ?string
    {
        if ($prospect->website_url === null || trim($prospect->website_url) === '') {
            return null;
        }

        if (ProspectResearchFixtures::isFixtureUrl($prospect->website_url)) {
            return $this->urlNormalizer->normalizeAbsolute($prospect->website_url);
        }

        return $this->websiteValidator->assertSafe($prospect->website_url);
    }

    /**
     * @return array{
     *     status: string,
     *     seed_url: string,
     *     pages: list<array<string, mixed>>,
     *     failures: list<array<string, mixed>>,
     *     pages_inspected: int,
     *     total_bytes: int
     * }
     */
    private function crawlSeed(string $seedUrl): array
    {
        if ($this->shouldUseFixtures($seedUrl)) {
            return ProspectResearchFixtures::crawl($seedUrl);
        }

        return $this->crawler->crawl($seedUrl);
    }

    private function shouldUseFixtures(string $seedUrl): bool
    {
        if (ProspectResearchFixtures::isFixtureUrl($seedUrl)) {
            return true;
        }

        return (bool) config('moxdop.prospect_research.fixtures', false);
    }

    /**
     * @param  array<string, mixed>  $crawl
     * @return list<int>
     */
    private function persistObservedEvidence(Prospect $prospect, ProspectResearchRun $run, array $crawl): array
    {
        $evidenceIds = [];
        $observedAt = now();

        foreach ($crawl['pages'] as $page) {
            if (! is_array($page)) {
                continue;
            }

            $extracted = is_array($page['extracted'] ?? null) ? $page['extracted'] : [];
            $sourceUrl = (string) ($page['final_url'] ?? $page['requested_url'] ?? '');
            $fingerprint = $this->evidenceFingerprint(
                ProspectResearchConfig::EVIDENCE_PAGE_SNAPSHOT,
                $sourceUrl,
            );

            $evidence = $this->upsertEvidence($prospect, $run, [
                'type' => ProspectResearchConfig::EVIDENCE_PAGE_SNAPSHOT,
                'title' => 'Public page: '.($extracted['title'] ?? $sourceUrl ?: 'page'),
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                'fingerprint' => $fingerprint,
                'provenance' => ProspectEvidenceProvenance::Observed,
                'payload' => [
                    'ok' => true,
                    'requested_url' => $page['requested_url'] ?? null,
                    'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                    'status_code' => $page['status_code'] ?? null,
                    'content_type' => $page['content_type'] ?? null,
                    'bytes' => $page['bytes'] ?? null,
                    'redirect_count' => $page['redirect_count'] ?? null,
                    'retrieved_at' => $observedAt->toIso8601String(),
                    'normalization_version' => ProspectResearchConfig::VERSION,
                    'extracted' => $extracted,
                ],
                'observed_at' => $observedAt,
            ]);

            $evidenceIds[] = (int) $evidence->id;
        }

        $summaryFingerprint = $this->evidenceFingerprint(
            ProspectResearchConfig::EVIDENCE_SITE_SUMMARY,
            (string) ($crawl['seed_url'] ?? ''),
        );

        $summary = $this->upsertEvidence($prospect, $run, [
            'type' => ProspectResearchConfig::EVIDENCE_SITE_SUMMARY,
            'title' => 'Public site summary',
            'source_url' => (string) ($crawl['seed_url'] ?? null),
            'fingerprint' => $summaryFingerprint,
            'provenance' => ProspectEvidenceProvenance::Derived,
            'payload' => [
                'ok' => ($crawl['status'] ?? 'failed') !== 'failed',
                'status' => $crawl['status'] ?? null,
                'seed_url' => $crawl['seed_url'] ?? null,
                'pages_inspected' => $crawl['pages_inspected'] ?? 0,
                'total_bytes' => $crawl['total_bytes'] ?? 0,
                'failures' => $crawl['failures'] ?? [],
                'page_urls' => array_values(array_filter(array_map(
                    static fn (array $page): ?string => isset($page['final_url']) && is_string($page['final_url']) ? $page['final_url'] : null,
                    is_array($crawl['pages'] ?? null) ? $crawl['pages'] : [],
                ))),
                'retrieved_at' => $observedAt->toIso8601String(),
                'normalization_version' => ProspectResearchConfig::VERSION,
            ],
            'observed_at' => $observedAt,
        ]);

        $evidenceIds[] = (int) $summary->id;

        return $evidenceIds;
    }

    /**
     * @param  array<string, mixed>  $crawl
     * @param  list<int>  $evidenceIds
     */
    private function persistFactCandidates(Prospect $prospect, ProspectResearchRun $run, array $crawl, array $evidenceIds): void
    {
        $summaryEvidenceId = $evidenceIds !== [] ? (int) end($evidenceIds) : null;
        $rows = $this->candidateBuilder->fromCrawl($crawl);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['candidate_kind'] ?? ProspectDiscoveryCandidate::KIND_FACT);
            $type = (string) ($row['candidate_type'] ?? 'fact');
            $field = (string) ($row['target_field'] ?? '');
            $value = trim((string) ($row['proposed_value'] ?? ''));
            if ($kind === '' || $type === '' || $field === '' || $value === '') {
                continue;
            }

            $support = is_array($row['support_json'] ?? null) ? $row['support_json'] : [];
            $sourceIdentity = (string) ($support['source_url'] ?? $support['domain'] ?? $support['provider'] ?? 'discovery');

            $fingerprint = hash('sha256', implode('|', [
                (string) $prospect->id,
                $kind,
                $type,
                $field,
                mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value)),
                mb_strtolower($sourceIdentity),
            ]));

            ProspectDiscoveryCandidate::query()->updateOrCreate(
                [
                    'prospect_id' => $prospect->id,
                    'fingerprint' => $fingerprint,
                ],
                [
                    'prospect_research_run_id' => $run->id,
                    'prospect_evidence_id' => $summaryEvidenceId,
                    'candidate_kind' => $kind,
                    'candidate_type' => $type,
                    'target_field' => $field,
                    'proposed_value' => $value,
                    'support_json' => $support,
                    'support_label' => isset($row['support_label']) ? (string) $row['support_label'] : null,
                    'provenance' => ProspectEvidenceProvenance::Observed,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertEvidence(Prospect $prospect, ProspectResearchRun $run, array $payload): ProspectEvidence
    {
        return ProspectEvidence::query()->updateOrCreate(
            [
                'prospect_id' => $prospect->id,
                'fingerprint' => $payload['fingerprint'],
            ],
            [
                'prospect_research_run_id' => $run->id,
                'type' => $payload['type'],
                'title' => $payload['title'],
                'source_url' => $payload['source_url'],
                'provenance' => $payload['provenance'],
                'payload' => $payload['payload'],
                'observed_at' => $payload['observed_at'],
            ],
        );
    }

    private function evidenceFingerprint(string $type, string $sourceUrl): string
    {
        return hash('sha256', ProspectResearchConfig::MODULE_ID.'|'.$type.'|'.strtolower(trim($sourceUrl)));
    }

    private function finishWithoutWebsite(ProspectResearchRun $run, Prospect $prospect, ?User $actor): ProspectResearchRun
    {
        $this->salesIntelligence->generate($prospect, $run, $actor);

        return $this->finalize(
            $run,
            $prospect,
            ProspectResearchRunStatus::Partial,
            'Website not provided / not discovered.',
            ['website_provided' => false],
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function finalize(
        ProspectResearchRun $run,
        Prospect $prospect,
        ProspectResearchRunStatus $status,
        string $message,
        array $metadata,
        ?User $actor,
    ): ProspectResearchRun {
        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], $metadata, [
                'message' => $message,
            ]),
        ]);

        $activityType = $status === ProspectResearchRunStatus::Failed
            ? 'prospect.research_failed'
            : 'prospect.research_completed';

        $this->activities->record(
            $prospect,
            $activityType,
            $status === ProspectResearchRunStatus::Failed
                ? __('operator.prospects.activity.research_failed')
                : __('operator.prospects.activity.research_completed'),
            $message,
            $actor,
            ['run_id' => $run->id, 'status' => $status->value],
        );

        return $run->fresh(['salesIntelligence', 'evidence', 'prospect']);
    }
}
