<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Support\Recommendations\Dto\RecommendationReadDto;
use App\Support\Recommendations\Dto\RecommendationSourceViewData;
use App\Support\Recommendations\RecommendationSourceReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Canonical Recommendation read boundary. Empty means empty — no Demo fallback.
 * Sources are hydrated in batch, never per row.
 */
final class RecommendationReadService
{
    /** @var list<string> */
    private const array AI_ASSISTED_SOURCE_MODULES = [
        'website-ai-insights',
        'google-ads-ai-guidance',
        'meta-ads-ai-guidance',
    ];

    public function __construct(
        private readonly RecommendationSourceResolver $resolver,
    ) {}

    /**
     * @param  array{
     *     source_kind?: string,
     *     finding_id?: int,
     *     opportunity_id?: int,
     *     status?: string|list<string>,
     *     digital_asset_id?: int,
     *     brand_id?: int,
     *     customer_id?: int,
     *     origin?: string,
     *     source_module?: string,
     *     priority?: string
     * }  $filters
     * @return list<RecommendationReadDto>
     */
    public function query(array $filters = [], int $limit = 200): array
    {
        $rows = $this->baseQuery($filters)
            ->with(['tasks'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $sources = $this->hydrateSources($rows);

        return $rows
            ->map(fn (Recommendation $recommendation): RecommendationReadDto => RecommendationReadDto::fromModel(
                $recommendation,
                $this->sourceFor($recommendation, $sources),
            ))
            ->all();
    }

    /**
     * @return list<RecommendationReadDto>
     */
    public function forFinding(Finding|int $finding): array
    {
        return $this->query([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'finding_id' => $finding instanceof Finding ? (int) $finding->id : $finding,
        ]);
    }

    /**
     * @return list<RecommendationReadDto>
     */
    public function forOpportunity(Opportunity|int $opportunity): array
    {
        return $this->query([
            'source_kind' => RecommendationSourceKind::Opportunity->value,
            'opportunity_id' => $opportunity instanceof Opportunity ? (int) $opportunity->id : $opportunity,
        ]);
    }

    /**
     * @return list<RecommendationReadDto>
     */
    public function forBrand(Brand $brand): array
    {
        return $this->query(['brand_id' => (int) $brand->id]);
    }

    /**
     * @return list<RecommendationReadDto>
     */
    public function forCustomer(Customer $customer): array
    {
        return $this->query(['customer_id' => (int) $customer->id]);
    }

    /**
     * @return list<RecommendationReadDto>
     */
    public function forAsset(DigitalAsset $asset): array
    {
        return $this->query(['digital_asset_id' => (int) $asset->id]);
    }

    /**
     * Maps production Recommendations to the frozen Operations decision-inbox card shape.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function forListPresentation(array $filters = [], int $limit = 200): array
    {
        $rows = $this->baseQuery($filters)
            ->with(['tasks', 'digitalAsset.brand'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $sources = $this->hydrateSources($rows);

        return $rows
            ->map(fn (Recommendation $recommendation): array => $this->toPresentation(
                $recommendation,
                $this->sourceFor($recommendation, $sources),
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toPresentation(Recommendation $recommendation, ?RecommendationSourceViewData $source): array
    {
        $asset = $recommendation->digitalAsset;
        $brand = $asset?->brand;
        $isAiAssisted = in_array((string) $recommendation->source_module, self::AI_ASSISTED_SOURCE_MODULES, true);

        return [
            'id' => (string) $recommendation->id,
            'source_kind' => $recommendation->source_kind,
            'finding_id' => $recommendation->finding_id === null ? null : (string) $recommendation->finding_id,
            'source_opportunity_id' => $recommendation->opportunity_id === null ? null : (string) $recommendation->opportunity_id,
            'source_title' => $source?->title,
            'source_status' => $source?->status,
            'origin' => $recommendation->origin,
            'origin_label' => $recommendation->origin() === null
                ? null
                : $recommendation->origin()->label(),
            'title' => (string) $recommendation->title,
            'action' => $recommendation->action,
            'why' => $recommendation->rationale ?? $source?->title ?? (string) $recommendation->title,
            'evidence' => $this->evidenceSummary($source),
            'status' => (string) $recommendation->status,
            'priority' => (string) $recommendation->priority,
            'effort' => $recommendation->effort,
            'provenance' => $isAiAssisted ? 'AI-assisted (operator accepted)' : 'Deterministic',
            'ai_assisted' => $isAiAssisted,
            'source_module' => (string) $recommendation->source_module,
            'brand_id' => $brand !== null ? (string) $brand->id : ($source?->brandId !== null ? (string) $source->brandId : null),
            'brand' => $brand?->name,
            'customer_id' => $source?->customerId !== null ? (string) $source->customerId : null,
            'asset' => $asset?->name,
            'asset_type' => $asset !== null ? (string) $asset->type : null,
            'service' => $source?->serviceContext,
            'market' => $source?->market,
            'category' => $source?->category,
            'task_ids' => $recommendation->tasks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
            'task_id' => $recommendation->tasks->isNotEmpty() ? (string) $recommendation->tasks->first()->id : null,
            'updated_at' => $recommendation->updated_at?->diffForHumans(),
        ];
    }

    private function evidenceSummary(?RecommendationSourceViewData $source): string
    {
        if ($source === null) {
            return '—';
        }

        $label = $source->kind === RecommendationSourceKind::Finding
            ? 'Finding #'.$source->id
            : 'Opportunity #'.$source->id;

        $parts = [$label, $source->title];

        if ($source->ruleId !== null) {
            $parts[] = 'Rule '.$source->ruleId;
        }

        $parts[] = $source->supportingEvidenceCount === 1
            ? '1 supporting Evidence row'
            : $source->supportingEvidenceCount.' supporting Evidence rows';

        return implode(' · ', $parts);
    }

    /**
     * @param  Collection<int, Recommendation>  $rows
     * @return array<string, RecommendationSourceViewData>
     */
    private function hydrateSources(Collection $rows): array
    {
        $references = [];

        foreach ($rows as $recommendation) {
            $reference = $this->referenceFor($recommendation);
            if ($reference !== null) {
                $references[$reference->key()] = $reference;
            }
        }

        if ($references === []) {
            return [];
        }

        return $this->resolver->resolveManyViewData(array_values($references));
    }

    /**
     * @param  array<string, RecommendationSourceViewData>  $sources
     */
    private function sourceFor(Recommendation $recommendation, array $sources): ?RecommendationSourceViewData
    {
        $reference = $this->referenceFor($recommendation);

        return $reference === null ? null : ($sources[$reference->key()] ?? null);
    }

    private function referenceFor(Recommendation $recommendation): ?RecommendationSourceReference
    {
        return match ($recommendation->sourceKind()) {
            RecommendationSourceKind::Finding => $recommendation->finding_id === null
                ? null
                : RecommendationSourceReference::fromFinding((int) $recommendation->finding_id),
            RecommendationSourceKind::Opportunity => $recommendation->opportunity_id === null
                ? null
                : RecommendationSourceReference::fromOpportunity((int) $recommendation->opportunity_id),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Recommendation>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Recommendation::query();

        if (isset($filters['source_kind'])) {
            $query->where('source_kind', (string) $filters['source_kind']);
        }
        if (isset($filters['finding_id'])) {
            $query->where('finding_id', (int) $filters['finding_id']);
        }
        if (isset($filters['opportunity_id'])) {
            $query->where('opportunity_id', (int) $filters['opportunity_id']);
        }
        if (isset($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', array_map(static fn (mixed $status): string => (string) $status, $statuses));
        }
        if (isset($filters['priority'])) {
            $query->where('priority', (string) $filters['priority']);
        }
        if (isset($filters['origin'])) {
            $origin = $filters['origin'];
            $query->where('origin', $origin instanceof RecommendationOrigin ? $origin->value : (string) $origin);
        }
        if (isset($filters['source_module'])) {
            $query->where('source_module', (string) $filters['source_module']);
        }
        if (isset($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (isset($filters['brand_id'])) {
            $brandId = (int) $filters['brand_id'];
            $query->where(function (Builder $scoped) use ($brandId): void {
                $scoped
                    ->whereHas('digitalAsset', fn (Builder $asset): Builder => $asset->where('brand_id', $brandId))
                    ->orWhereHas('finding', fn (Builder $finding): Builder => $finding->where('brand_id', $brandId))
                    ->orWhereHas('opportunity', fn (Builder $opportunity): Builder => $opportunity->where('brand_id', $brandId));
            });
        }
        if (isset($filters['customer_id'])) {
            $customerId = (int) $filters['customer_id'];
            $query->where(function (Builder $scoped) use ($customerId): void {
                $scoped
                    ->whereHas('finding', fn (Builder $finding): Builder => $finding->where('customer_id', $customerId))
                    ->orWhereHas('opportunity', fn (Builder $opportunity): Builder => $opportunity->where('customer_id', $customerId));
            });
        }

        return $query;
    }
}
