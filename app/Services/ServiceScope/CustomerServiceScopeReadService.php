<?php

namespace App\Services\ServiceScope;

use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;

/**
 * Production DB-backed Service Scope reads. Never falls back to Demo fixtures.
 */
final class CustomerServiceScopeReadService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomer(Customer $customer, bool $includeEnded = true): array
    {
        $query = CustomerServiceScope::query()
            ->where('customer_id', $customer->id)
            ->with(['serviceDefinition', 'owner', 'brands', 'inclusions', 'exclusions'])
            ->orderBy('id');

        if (! $includeEnded) {
            $query->where('status', '!=', ServiceScopeStatus::Ended->value);
        }

        return $query->get()
            ->map(fn (CustomerServiceScope $scope): array => $this->toViewData($scope))
            ->all();
    }

    /**
     * Customer-wide scopes UNION scopes that explicitly include this Brand.
     *
     * @return list<array<string, mixed>>
     */
    public function forBrand(Brand $brand, bool $includeEnded = false): array
    {
        $scopes = CustomerServiceScope::query()
            ->where('customer_id', $brand->customer_id)
            ->with(['serviceDefinition', 'owner', 'brands', 'inclusions', 'exclusions'])
            ->orderBy('id')
            ->get()
            ->filter(function (CustomerServiceScope $scope) use ($brand, $includeEnded): bool {
                if (! $includeEnded && $scope->status === ServiceScopeStatus::Ended) {
                    return false;
                }

                return $scope->appliesToBrand($brand);
            })
            ->values();

        return $scopes
            ->map(fn (CustomerServiceScope $scope): array => $this->toViewData($scope))
            ->all();
    }

    /**
     * Active service codes for legacy Multiselect projection consumers.
     *
     * @return list<string>
     */
    public function activeServiceCodes(Customer $customer): array
    {
        return CustomerServiceScope::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                ServiceScopeStatus::Active->value,
                ServiceScopeStatus::Paused->value,
            ])
            ->with('serviceDefinition')
            ->get()
            ->map(fn (CustomerServiceScope $scope): ?string => $scope->serviceDefinition?->code)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toViewData(CustomerServiceScope $scope): array
    {
        $brandMode = $scope->brand_applicability_mode;
        $brands = $scope->relationLoaded('brands') ? $scope->brands : $scope->brands()->get();

        return [
            'id' => $scope->id,
            'service_code' => $scope->serviceDefinition?->code,
            'service_label' => $scope->serviceDefinition?->name,
            'status' => $scope->status->value,
            'brand_applicability_mode' => $brandMode->value,
            'applies_to_brand_ids' => $brandMode === ServiceBrandApplicabilityMode::CustomerWide
                ? []
                : $brands->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'applies_to_brand_names' => $brandMode === ServiceBrandApplicabilityMode::CustomerWide
                ? ['Customer-wide']
                : $brands->pluck('name')->all(),
            'owner_id' => $scope->owner_user_id,
            'owner_name' => $scope->owner?->name,
            'review_cadence' => $scope->cadence?->value,
            'reporting_cadence' => $scope->reporting_cadence?->value,
            'started_at' => $scope->started_at?->toDateString(),
            'paused_at' => $scope->paused_at?->toIso8601String(),
            'ended_at' => $scope->ended_at?->toIso8601String(),
            'in_scope' => $scope->inclusions->pluck('text')->all(),
            'out_of_scope' => $scope->exclusions->pluck('text')->all(),
            'note' => $scope->note,
            'source_state' => 'REAL',
        ];
    }
}
