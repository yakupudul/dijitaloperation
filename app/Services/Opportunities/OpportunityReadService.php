<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityCommercialScopeState;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Support\Opportunities\Dto\OpportunityReadDto;
use App\Support\Options\AgencyServiceOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Opportunity read boundary. Empty means empty — no Demo fallback.
 */
final class OpportunityReadService
{
    /** @var array<string, string> */
    private const array EVIDENCE_SOURCE_LABELS = [
        'gsc.property.period_comparison' => 'Search Console',
        'ga4.property.period_comparison' => 'GA4',
    ];

    /**
     * @param  array{
     *     customer_id?: int,
     *     brand_id?: int,
     *     digital_asset_id?: int,
     *     status?: string,
     *     category?: string,
     *     rule_id?: string,
     *     origin?: string,
     *     service_definition_code?: string,
     *     subject_kind?: string,
     *     subject_id?: string,
     *     brand_goal_id?: int,
     *     brand_offering_id?: int
     * }  $filters
     * @return list<OpportunityReadDto>
     */
    public function query(array $filters = [], int $limit = 200): array
    {
        $rows = $this->baseQuery($filters)
            ->with(['latestEvaluation.evidence', 'latestEvaluation.findings'])
            ->orderByDesc('last_detected_at')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (Opportunity $opportunity): OpportunityReadDto => OpportunityReadDto::fromModel($opportunity))->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, OpportunityEvaluation>
     */
    public function paginateEvaluations(Opportunity $opportunity, int $perPage = 25): LengthAwarePaginator
    {
        return $opportunity->evaluations()->orderByDesc('evaluated_at')->paginate($perPage);
    }

    /**
     * @return list<OpportunityReadDto>
     */
    public function forCustomer(Customer $customer): array
    {
        return $this->query(['customer_id' => $customer->id]);
    }

    /**
     * @return list<OpportunityReadDto>
     */
    public function forBrand(Brand $brand): array
    {
        return $this->query(['brand_id' => $brand->id]);
    }

    /**
     * @return list<OpportunityReadDto>
     */
    public function forAsset(DigitalAsset $asset): array
    {
        return $this->query(['digital_asset_id' => $asset->id]);
    }

    /**
     * Maps production Opportunities to the frozen Operations Opportunities card shape.
     * No "opportunity_score" field — qualitative priority only. Empty means empty.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function forListPresentation(array $filters = [], int $limit = 200): array
    {
        $rows = $this->baseQuery($filters)
            ->with([
                'brand.customer',
                'customer',
                'digitalAsset',
                'brandGoal',
                'brandOffering.primaryName',
                'latestEvaluation.evidence',
            ])
            ->orderByDesc('last_detected_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Opportunity $opportunity): array => $this->toPresentation($opportunity))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toPresentation(Opportunity $opportunity): array
    {
        $brand = $opportunity->brand;
        $customer = $opportunity->customer ?? $brand?->customer;
        $goal = $opportunity->brandGoal;
        $offering = $opportunity->brandOffering;
        $asset = $opportunity->digitalAsset;

        $evidenceSummaries = [];
        if ($opportunity->latestEvaluation !== null) {
            foreach ($opportunity->latestEvaluation->evidence as $evidenceRow) {
                $evidenceSummaries[] = [
                    'source' => self::EVIDENCE_SOURCE_LABELS[$evidenceRow->definition_id] ?? $evidenceRow->definition_id,
                    'provenance' => 'Deterministic',
                    'summary' => $evidenceRow->title,
                ];
            }
        }

        $whyMatters = ['Rule-Engine Evidence'];
        if ($opportunity->commercial_scope_state === OpportunityCommercialScopeState::InCurrentScope->value) {
            $whyMatters[] = 'Active Service';
        }
        if ($goal !== null) {
            $whyMatters[] = 'Linked Goal';
        }
        if ($offering !== null) {
            $whyMatters[] = 'Linked Offering';
        }

        $isNew = $opportunity->first_detected_at !== null
            && $opportunity->first_detected_at->greaterThan(now()->subDays(7));

        $market = collect([$opportunity->market_location, $opportunity->market_language])
            ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
            ->implode(' · ');

        return [
            'id' => (string) $opportunity->id,
            'title' => $opportunity->title,
            'brand_id' => $brand !== null ? (string) $brand->id : null,
            'brand_name' => $brand?->name,
            'customer_id' => $customer !== null ? (string) $customer->id : null,
            'customer_name' => $customer?->name,
            'category' => $opportunity->category,
            'status' => $opportunity->status,
            'service_code' => $opportunity->service_definition_code,
            'service_label' => AgencyServiceOptions::label($opportunity->service_definition_code),
            'goal_id' => $goal !== null ? (string) $goal->id : null,
            'goal_title' => $goal?->label,
            'offering' => $offering?->primaryName?->raw_label,
            'market' => $market !== '' ? $market : null,
            'audience' => null,
            'asset_ids' => $asset !== null ? [$asset->id] : [],
            'asset_types' => $asset !== null ? [(string) $asset->type] : [],
            'evidence' => $evidenceSummaries,
            'why_matters' => $whyMatters,
            'what' => $opportunity->description ?? $opportunity->title,
            'why' => $opportunity->description ?? $opportunity->title,
            'known' => '',
            'unknown' => '',
            'observed_at' => $opportunity->last_detected_at?->diffForHumans(),
            'recommendation_id' => null,
            'is_new' => $isNew,
            'ai_assisted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Opportunity>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Opportunity::query();

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (isset($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (isset($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (isset($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }
        if (isset($filters['rule_id'])) {
            $query->where('rule_id', (string) $filters['rule_id']);
        }
        if (isset($filters['origin'])) {
            $query->where('origin', (string) $filters['origin']);
        }
        if (isset($filters['service_definition_code'])) {
            $query->where('service_definition_code', (string) $filters['service_definition_code']);
        }
        if (isset($filters['subject_kind'], $filters['subject_id'])) {
            $query->where('subject_kind', (string) $filters['subject_kind'])
                ->where('subject_id', (string) $filters['subject_id']);
        }
        if (isset($filters['brand_goal_id'])) {
            $query->where('brand_goal_id', (int) $filters['brand_goal_id']);
        }
        if (isset($filters['brand_offering_id'])) {
            $query->where('brand_offering_id', (int) $filters['brand_offering_id']);
        }

        return $query;
    }
}
