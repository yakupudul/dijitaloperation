<?php

namespace MoxDop\Website\Ai;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Services\BrandIntelligence\BrandContextProvider;
use App\Support\BrandIntelligence\BrandIntelligenceSnapshot;
use Illuminate\Support\Collection;

/**
 * Builds the bounded AI Recommendation Context snapshot.
 */
final class WebsiteAiRecommendationContextBuilder
{
    public function __construct(
        private readonly BrandContextProvider $brandContext,
    ) {}

    /**
     * @param  list<int>|null  $findingIds
     * @return array{
     *     findings: Collection<int, Finding>,
     *     context: array<string, mixed>,
     *     brand_snapshot: BrandIntelligenceSnapshot,
     *     finding_ids: list<int>,
     *     evidence_ids: list<int>
     * }
     */
    public function build(DigitalAsset $asset, ?array $findingIds = null): array
    {
        $findings = $this->resolveFindings($asset, $findingIds);
        $brand = $asset->brand ?? $asset->brand()->first();
        $brandSnapshot = $brand !== null
            ? $this->brandContext->for($brand)
            : $this->emptyBrandSnapshot((int) ($asset->brand_id ?? 0));

        $evidence = $this->loadSupportingEvidence($asset, $findings);
        $recommendations = $this->loadDeterministicRecommendations($asset, $findings);

        $findingPayload = $findings->map(fn (Finding $finding): array => [
            'id' => $finding->id,
            'fingerprint' => $finding->fingerprint,
            'category' => $finding->category,
            'severity' => $finding->severity,
            'title' => $finding->title,
            'summary' => $this->boundString($finding->summary),
            'confidence' => $finding->confidence,
            'status' => $finding->status,
            'first_seen_at' => optional($finding->first_seen_at)?->toIso8601String(),
            'last_seen_at' => optional($finding->last_seen_at)?->toIso8601String(),
            'last_run_id' => $finding->last_run_id,
        ])->values()->all();

        $evidencePayload = $evidence->map(fn (Evidence $row): array => [
            'id' => $row->id,
            'run_id' => $row->run_id,
            'type' => $row->type,
            'title' => $this->boundString($row->title),
            'source_module' => $row->source_module,
            'payload' => $this->redactPayload($row->payload ?? []),
            'observed_at' => optional($row->observed_at)?->toIso8601String(),
        ])->values()->all();

        $recommendationPayload = $recommendations->map(fn (Recommendation $row): array => [
            'id' => $row->id,
            'finding_id' => $row->finding_id,
            'source_module' => $row->source_module,
            'title' => $this->boundString($row->title),
            'action' => $this->boundString($row->action),
            'rationale' => $this->boundString($row->rationale),
            'priority' => $row->priority,
            'status' => $row->status,
            'origin' => $row->source_module === WebsiteAiRecommendationConfig::MODULE_ID
                ? 'ai_assisted'
                : 'deterministic',
        ])->values()->all();

        $brandArray = $this->brandFactsForPrompt($brandSnapshot);

        $context = [
            'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
            'schema_version' => WebsiteAiRecommendationConfig::SCHEMA_VERSION,
            'digital_asset' => [
                'id' => $asset->id,
                'type' => $asset->type,
                'name' => $asset->name,
                'primary_url' => $asset->primary_url,
                'domain' => $asset->domain,
            ],
            'brand_intelligence' => $brandArray,
            'findings' => $findingPayload,
            'evidence' => $evidencePayload,
            'deterministic_recommendations' => array_values(array_filter(
                $recommendationPayload,
                fn (array $row): bool => ($row['origin'] ?? '') === 'deterministic',
            )),
        ];

        return [
            'findings' => $findings,
            'context' => $context,
            'brand_snapshot' => $brandSnapshot,
            'finding_ids' => $findings->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'evidence_ids' => $evidence->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  list<int>|null  $findingIds
     * @return Collection<int, Finding>
     */
    private function resolveFindings(DigitalAsset $asset, ?array $findingIds): Collection
    {
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        if ($findingIds !== null) {
            $ids = collect($findingIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return collect();
            }

            $findings = Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->whereIn('id', $ids->all())
                ->get();

            if ($findings->count() !== $ids->count()) {
                throw new \InvalidArgumentException('Website AI insights finding_ids must belong to the given website Digital Asset.');
            }

            return $findings
                ->sortBy(fn (Finding $finding): array => [
                    $severityOrder[$finding->severity] ?? 99,
                    $finding->id,
                ])
                ->values();
        }

        return Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->get()
            ->sortBy(fn (Finding $finding): array => [
                $severityOrder[$finding->severity] ?? 99,
                $finding->id,
            ])
            ->take(WebsiteAiRecommendationConfig::MAX_FINDINGS)
            ->values();
    }

    /**
     * @param  Collection<int, Finding>  $findings
     * @return Collection<int, Evidence>
     */
    private function loadSupportingEvidence(DigitalAsset $asset, Collection $findings): Collection
    {
        $runIds = $findings
            ->pluck('last_run_id')
            ->filter()
            ->unique()
            ->values();

        if ($runIds->isEmpty()) {
            return collect();
        }

        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('run_id', $runIds->all())
            ->where('type', '!=', WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT)
            ->where(function ($query): void {
                $query->whereNull('source_module')
                    ->orWhere('source_module', '!=', WebsiteAiRecommendationConfig::MODULE_ID);
            })
            ->orderBy('id')
            ->limit(WebsiteAiRecommendationConfig::MAX_EVIDENCE)
            ->get()
            ->filter(function (Evidence $evidence): bool {
                $payload = $evidence->payload ?? [];
                if (! is_array($payload)) {
                    return true;
                }

                // Exclude derived AI insight even if type/source_module drifted.
                return ($payload['generated_by_ai'] ?? false) !== true
                    && ($payload['derived'] ?? false) !== true;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Finding>  $findings
     * @return Collection<int, Recommendation>
     */
    private function loadDeterministicRecommendations(DigitalAsset $asset, Collection $findings): Collection
    {
        if ($findings->isEmpty()) {
            return collect();
        }

        return Recommendation::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('finding_id', $findings->pluck('id')->all())
            ->where('source_module', '!=', WebsiteAiRecommendationConfig::MODULE_ID)
            ->whereIn('status', ['open', 'accepted'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function brandFactsForPrompt(BrandIntelligenceSnapshot $snapshot): array
    {
        $facts = [
            'brand_id' => $snapshot->brandId,
            'brand_name' => $snapshot->brandName,
            'has_context' => $snapshot->hasContext,
            'business_summary' => $snapshot->businessSummary,
            'business_model' => $snapshot->businessModel,
            'business_model_label' => $snapshot->businessModelLabel,
            'offerings' => $snapshot->offerings,
            'priority_offerings' => $snapshot->priorityOfferings,
            'target_audiences' => $snapshot->targetAudiences,
            'target_markets' => $snapshot->targetMarkets,
            'business_goals' => $snapshot->businessGoals,
            'conversion_goals' => $snapshot->conversionGoals,
            'positioning' => $snapshot->positioning,
            'differentiators' => $snapshot->differentiators,
            'competitors' => $snapshot->competitors,
            'important_constraints' => $snapshot->importantConstraints,
            'completeness' => $snapshot->completeness,
        ];

        // Drop null / empty arrays so unknown fields remain absent.
        return $this->stripEmpty($facts);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload, int $depth = 0): array
    {
        if ($depth >= WebsiteAiRecommendationConfig::MAX_NESTING_DEPTH) {
            return ['_truncated' => 'max_depth'];
        }

        $blocked = WebsiteAiRecommendationConfig::blockedPayloadKeys();
        $out = [];
        $count = 0;

        foreach ($payload as $key => $value) {
            if ($count >= WebsiteAiRecommendationConfig::MAX_ARRAY_ROWS) {
                $out['_truncated_rows'] = true;
                break;
            }

            if (! is_string($key)) {
                continue;
            }

            $keyLower = strtolower($key);
            if (in_array($keyLower, $blocked, true)) {
                continue;
            }

            foreach ($blocked as $blockedKey) {
                if (str_contains($keyLower, $blockedKey)) {
                    continue 2;
                }
            }

            if (is_string($value)) {
                $out[$key] = $this->boundString($value);
                $count++;

                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $rows = array_slice($value, 0, WebsiteAiRecommendationConfig::MAX_ARRAY_ROWS);
                    $mapped = [];
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $mapped[] = $this->redactPayload($row, $depth + 1);
                        } elseif (is_string($row)) {
                            $mapped[] = $this->boundString($row);
                        } elseif (is_scalar($row) || $row === null) {
                            $mapped[] = $row;
                        }
                    }
                    $out[$key] = $mapped;
                    if (count($value) > count($mapped)) {
                        $out[$key.'_truncated'] = true;
                    }
                } else {
                    $out[$key] = $this->redactPayload($value, $depth + 1);
                }
                $count++;

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
                $count++;
            }
        }

        return $out;
    }

    private function boundString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if (mb_strlen($trimmed) <= WebsiteAiRecommendationConfig::MAX_STRING_LENGTH) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, WebsiteAiRecommendationConfig::MAX_STRING_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function stripEmpty(array $facts): array
    {
        $out = [];
        foreach ($facts as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function emptyBrandSnapshot(int $brandId): BrandIntelligenceSnapshot
    {
        return new BrandIntelligenceSnapshot(
            brandId: $brandId,
            brandName: '',
            hasContext: false,
            businessSummary: null,
            businessModel: null,
            businessModelLabel: null,
            offerings: [],
            priorityOfferings: [],
            targetAudiences: [],
            targetMarkets: [],
            businessGoals: [],
            conversionGoals: [],
            positioning: null,
            differentiators: [],
            competitors: [],
            importantConstraints: null,
            source: 'operator',
            completeness: [
                'completed' => 0,
                'total' => 8,
                'areas' => [],
                'label' => '0 of 8 areas completed',
            ],
        );
    }
}
