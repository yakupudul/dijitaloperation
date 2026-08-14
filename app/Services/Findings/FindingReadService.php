<?php

namespace App\Services\Findings;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Support\Findings\Dto\FindingReadDto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Finding read boundary. Empty means empty — no Demo fallback.
 */
final class FindingReadService
{
    /**
     * @param  array{
     *     customer_id?: int,
     *     brand_id?: int,
     *     digital_asset_id?: int,
     *     status?: string,
     *     category?: string,
     *     severity?: string,
     *     rule_id?: string,
     *     origin?: string,
     *     subject_kind?: string,
     *     subject_id?: string,
     *     brand_goal_id?: int,
     *     brand_offering_id?: int
     * }  $filters
     * @return list<FindingReadDto>
     */
    public function query(array $filters = [], int $limit = 100): array
    {
        $rows = $this->baseQuery($filters)
            ->with(['latestEvaluation.evidence'])
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (Finding $finding): FindingReadDto => FindingReadDto::fromModel($finding))->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Finding>
     */
    public function paginateEvaluations(Finding $finding, int $perPage = 25): LengthAwarePaginator
    {
        return $finding->evaluations()->orderByDesc('evaluated_at')->paginate($perPage);
    }

    public function forCustomer(Customer $customer): array
    {
        return $this->query(['customer_id' => $customer->id]);
    }

    public function forBrand(Brand $brand): array
    {
        return $this->query(['brand_id' => $brand->id]);
    }

    public function forAsset(DigitalAsset $asset): array
    {
        return $this->query(['digital_asset_id' => $asset->id]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Finding>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Finding::query();

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
        if (isset($filters['severity'])) {
            $query->where('severity', (string) $filters['severity']);
        }
        if (isset($filters['rule_id'])) {
            $query->where('rule_id', (string) $filters['rule_id']);
        }
        if (isset($filters['origin'])) {
            $query->where('origin', (string) $filters['origin']);
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
