<?php

namespace App\Services\ServiceScope;

use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceCadence;
use App\Enums\ServiceCatalogStatus;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\CustomerServiceScopeExclusion;
use App\Models\CustomerServiceScopeInclusion;
use App\Models\ServiceDefinition;
use App\Models\User;
use App\Support\Options\AgencyServiceOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical write path for Customer Service Scope.
 *
 * Does not create Tasks, Playbooks, Goals, DigitalAssets, Evidence, or provider mutations.
 */
final class CustomerServiceScopeService
{
    /**
     * @param  list<int>  $brandIds
     * @param  list<string>  $inclusions
     * @param  list<string>  $exclusions
     */
    public function create(
        Customer $customer,
        ServiceDefinition $service,
        ServiceScopeStatus $status,
        ServiceBrandApplicabilityMode $brandMode,
        array $brandIds = [],
        ?User $owner = null,
        ?ServiceCadence $cadence = null,
        ?ServiceCadence $reportingCadence = null,
        array $inclusions = [],
        array $exclusions = [],
        ?string $note = null,
        ?\DateTimeInterface $startedAt = null,
    ): CustomerServiceScope {
        $this->assertServiceAssignable($service);
        $this->assertOwner($owner);
        $this->assertBrandApplicability($customer, $brandMode, $brandIds);
        $this->assertInclusionExclusionConflict($inclusions, $exclusions);
        $this->assertNoDuplicateActiveScope($customer, $service, $status);

        return DB::transaction(function () use (
            $customer,
            $service,
            $status,
            $brandMode,
            $brandIds,
            $owner,
            $cadence,
            $reportingCadence,
            $inclusions,
            $exclusions,
            $note,
            $startedAt,
        ): CustomerServiceScope {
            $scope = CustomerServiceScope::query()->create([
                'customer_id' => $customer->id,
                'service_definition_id' => $service->id,
                'status' => $status,
                'brand_applicability_mode' => $brandMode,
                'owner_user_id' => $owner?->id,
                'cadence' => $cadence ?? ServiceCadence::Unspecified,
                'reporting_cadence' => $reportingCadence,
                'started_at' => $startedAt,
                'paused_at' => $status === ServiceScopeStatus::Paused ? now() : null,
                'ended_at' => $status === ServiceScopeStatus::Ended ? now() : null,
                'note' => $note,
            ]);

            $this->syncBrands($scope, $brandMode, $brandIds);
            $this->replaceInclusions($scope, $inclusions);
            $this->replaceExclusions($scope, $exclusions);
            $this->syncLegacyCustomerServicesProjection($customer->fresh() ?? $customer);

            return $scope->fresh([
                'serviceDefinition',
                'owner',
                'brands',
                'inclusions',
                'exclusions',
            ]) ?? $scope;
        });
    }

    public function changeStatus(CustomerServiceScope $scope, ServiceScopeStatus $next): CustomerServiceScope
    {
        $scope = $scope->fresh() ?? $scope;
        $current = $scope->status;

        if ($current === $next) {
            return $scope;
        }

        if (! $current->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition Service Scope from {$current->value} to {$next->value}.",
            ]);
        }

        return DB::transaction(function () use ($scope, $next): CustomerServiceScope {
            $scope->status = $next;
            $scope->paused_at = $next === ServiceScopeStatus::Paused ? now() : ($next === ServiceScopeStatus::Active ? null : $scope->paused_at);
            $scope->ended_at = $next === ServiceScopeStatus::Ended ? now() : null;
            $scope->save();

            $this->syncLegacyCustomerServicesProjection($scope->customer);

            return $scope->fresh([
                'serviceDefinition',
                'owner',
                'brands',
                'inclusions',
                'exclusions',
            ]) ?? $scope;
        });
    }

    /**
     * @param  list<int>  $brandIds
     */
    public function setBrands(
        CustomerServiceScope $scope,
        ServiceBrandApplicabilityMode $mode,
        array $brandIds = [],
    ): CustomerServiceScope {
        $scope = $scope->fresh(['customer']) ?? $scope;
        $this->assertBrandApplicability($scope->customer, $mode, $brandIds);

        return DB::transaction(function () use ($scope, $mode, $brandIds): CustomerServiceScope {
            $scope->brand_applicability_mode = $mode;
            $scope->save();
            $this->syncBrands($scope, $mode, $brandIds);

            return $scope->fresh(['brands', 'serviceDefinition', 'owner', 'inclusions', 'exclusions']) ?? $scope;
        });
    }

    public function assignOwner(CustomerServiceScope $scope, ?User $owner): CustomerServiceScope
    {
        $this->assertOwner($owner);
        $scope = $scope->fresh() ?? $scope;
        $scope->owner_user_id = $owner?->id;
        $scope->save();

        return $scope->fresh(['owner', 'serviceDefinition', 'brands', 'inclusions', 'exclusions']) ?? $scope;
    }

    public function setCadence(
        CustomerServiceScope $scope,
        ?ServiceCadence $cadence,
        ?ServiceCadence $reportingCadence = null,
    ): CustomerServiceScope {
        $scope = $scope->fresh() ?? $scope;
        $scope->cadence = $cadence ?? ServiceCadence::Unspecified;
        $scope->reporting_cadence = $reportingCadence;
        $scope->save();

        return $scope->fresh(['serviceDefinition', 'owner', 'brands', 'inclusions', 'exclusions']) ?? $scope;
    }

    /**
     * @param  list<string>  $inclusions
     * @param  list<string>  $exclusions
     */
    public function replaceBoundaries(
        CustomerServiceScope $scope,
        array $inclusions,
        array $exclusions,
    ): CustomerServiceScope {
        $this->assertInclusionExclusionConflict($inclusions, $exclusions);
        $scope = $scope->fresh() ?? $scope;

        return DB::transaction(function () use ($scope, $inclusions, $exclusions): CustomerServiceScope {
            $this->replaceInclusions($scope, $inclusions);
            $this->replaceExclusions($scope, $exclusions);

            return $scope->fresh(['inclusions', 'exclusions', 'serviceDefinition', 'owner', 'brands']) ?? $scope;
        });
    }

    /**
     * Align ACTIVE Customer-wide scopes with Multiselect codes (one-way from form).
     * Ends scopes for removed codes; creates scopes for new codes. No owner/Brand inference.
     *
     * @param  list<string>  $codes
     */
    public function syncActiveCustomerWideFromCodes(Customer $customer, array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes)));

        DB::transaction(function () use ($customer, $codes): void {
            $existing = CustomerServiceScope::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [
                    ServiceScopeStatus::Active->value,
                    ServiceScopeStatus::Paused->value,
                    ServiceScopeStatus::Draft->value,
                ])
                ->with('serviceDefinition')
                ->get();

            $existingByCode = $existing->keyBy(fn (CustomerServiceScope $scope): string => (string) $scope->serviceDefinition?->code);

            foreach ($codes as $code) {
                if ($existingByCode->has($code)) {
                    continue;
                }

                $definition = ServiceDefinition::query()->where('code', $code)->first();
                if ($definition === null || ! $definition->isAssignable()) {
                    continue;
                }

                CustomerServiceScope::query()->create([
                    'customer_id' => $customer->id,
                    'service_definition_id' => $definition->id,
                    'status' => ServiceScopeStatus::Active,
                    'brand_applicability_mode' => ServiceBrandApplicabilityMode::CustomerWide,
                    'owner_user_id' => null,
                    'cadence' => ServiceCadence::Unspecified,
                    'started_at' => $customer->service_started_at,
                ]);
            }

            foreach ($existingByCode as $code => $scope) {
                if ($code === '' || in_array($code, $codes, true)) {
                    continue;
                }

                if ($scope->status !== ServiceScopeStatus::Ended) {
                    $this->changeStatus($scope, ServiceScopeStatus::Ended);
                }
            }

            $this->syncLegacyCustomerServicesProjection($customer->fresh() ?? $customer);
        });
    }

    /**
     * Idempotent backfill from legacy customers.services codes → ACTIVE customer_wide scopes.
     * Does not invent owner, cadence, brands, or inclusions.
     */
    public function migrateLegacyCustomerServices(Customer $customer): int
    {
        $codes = array_values(array_unique(array_filter(
            is_array($customer->services) ? $customer->services : []
        )));

        if ($codes === []) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($customer, $codes, &$created): void {
            foreach ($codes as $code) {
                $definition = ServiceDefinition::query()->where('code', $code)->first();
                if ($definition === null) {
                    continue;
                }

                $exists = CustomerServiceScope::query()
                    ->where('customer_id', $customer->id)
                    ->where('service_definition_id', $definition->id)
                    ->whereIn('status', [
                        ServiceScopeStatus::Active->value,
                        ServiceScopeStatus::Paused->value,
                        ServiceScopeStatus::Draft->value,
                    ])
                    ->exists();

                if ($exists) {
                    continue;
                }

                CustomerServiceScope::query()->create([
                    'customer_id' => $customer->id,
                    'service_definition_id' => $definition->id,
                    'status' => ServiceScopeStatus::Active,
                    'brand_applicability_mode' => ServiceBrandApplicabilityMode::CustomerWide,
                    'owner_user_id' => null,
                    'cadence' => ServiceCadence::Unspecified,
                    'reporting_cadence' => null,
                    'started_at' => $customer->service_started_at,
                    'note' => 'Migrated from legacy customers.services',
                ]);
                $created++;
            }

            $this->syncLegacyCustomerServicesProjection($customer->fresh() ?? $customer);
        });

        return $created;
    }

    /**
     * One-way projection: ACTIVE (+PAUSED for visibility of sold services) codes → customers.services.
     */
    public function syncLegacyCustomerServicesProjection(Customer $customer): void
    {
        $codes = CustomerServiceScope::query()
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

        $customer->services = $codes;
        $customer->services_received = AgencyServiceOptions::toLegacyText($codes);
        $customer->save();
    }

    /**
     * @param  list<int>  $brandIds
     */
    private function syncBrands(
        CustomerServiceScope $scope,
        ServiceBrandApplicabilityMode $mode,
        array $brandIds,
    ): void {
        if ($mode === ServiceBrandApplicabilityMode::CustomerWide) {
            $scope->brands()->detach();

            return;
        }

        $scope->brands()->sync(array_values(array_unique($brandIds)));
    }

    /**
     * @param  list<string>  $items
     */
    private function replaceInclusions(CustomerServiceScope $scope, array $items): void
    {
        $scope->inclusions()->delete();
        $order = 0;
        foreach ($this->normalizeBoundaryItems($items) as $text) {
            CustomerServiceScopeInclusion::query()->create([
                'customer_service_scope_id' => $scope->id,
                'text' => $text,
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function replaceExclusions(CustomerServiceScope $scope, array $items): void
    {
        $scope->exclusions()->delete();
        $order = 0;
        foreach ($this->normalizeBoundaryItems($items) as $text) {
            CustomerServiceScopeExclusion::query()->create([
                'customer_service_scope_id' => $scope->id,
                'text' => $text,
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function normalizeBoundaryItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $text = trim(strip_tags((string) $item));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 500) {
                throw ValidationException::withMessages([
                    'boundaries' => 'Inclusion/exclusion text may not exceed 500 characters.',
                ]);
            }
            $out[] = $text;
        }

        if (count($out) > 50) {
            throw ValidationException::withMessages([
                'boundaries' => 'At most 50 inclusion/exclusion items are allowed.',
            ]);
        }

        return array_values($out);
    }

    private function assertServiceAssignable(ServiceDefinition $service): void
    {
        if ($service->catalog_status !== ServiceCatalogStatus::Available) {
            throw ValidationException::withMessages([
                'service' => 'Archived services cannot receive new Service Scopes.',
            ]);
        }
    }

    private function assertOwner(?User $owner): void
    {
        if ($owner === null) {
            return;
        }

        if (! $owner->exists) {
            throw ValidationException::withMessages([
                'owner' => 'Owner must be a valid team user.',
            ]);
        }
    }

    /**
     * @param  list<int>  $brandIds
     */
    private function assertBrandApplicability(
        Customer $customer,
        ServiceBrandApplicabilityMode $mode,
        array $brandIds,
    ): void {
        if ($mode === ServiceBrandApplicabilityMode::CustomerWide) {
            if ($brandIds !== []) {
                throw ValidationException::withMessages([
                    'brands' => 'Customer-wide Service Scope must not attach specific Brand rows.',
                ]);
            }

            return;
        }

        if ($brandIds === []) {
            throw ValidationException::withMessages([
                'brands' => 'Specific-Brand Service Scope requires at least one Brand.',
            ]);
        }

        $validCount = Brand::query()
            ->where('customer_id', $customer->id)
            ->whereIn('id', $brandIds)
            ->count();

        if ($validCount !== count(array_unique($brandIds))) {
            throw ValidationException::withMessages([
                'brands' => 'All Brands must belong to the Service Scope Customer.',
            ]);
        }
    }

    /**
     * @param  list<string>  $inclusions
     * @param  list<string>  $exclusions
     */
    private function assertInclusionExclusionConflict(array $inclusions, array $exclusions): void
    {
        $normalizedIncludes = array_map(
            static fn (string $t): string => mb_strtolower(trim(strip_tags($t))),
            $this->normalizeBoundaryItems($inclusions)
        );
        $normalizedExcludes = array_map(
            static fn (string $t): string => mb_strtolower(trim(strip_tags($t))),
            $this->normalizeBoundaryItems($exclusions)
        );

        $overlap = array_values(array_intersect($normalizedIncludes, $normalizedExcludes));
        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'boundaries' => 'The same exact item cannot be both included and excluded.',
            ]);
        }
    }

    private function assertNoDuplicateActiveScope(
        Customer $customer,
        ServiceDefinition $service,
        ServiceScopeStatus $status,
    ): void {
        if (! in_array($status, [ServiceScopeStatus::Active, ServiceScopeStatus::Paused, ServiceScopeStatus::Draft], true)) {
            return;
        }

        $exists = CustomerServiceScope::query()
            ->where('customer_id', $customer->id)
            ->where('service_definition_id', $service->id)
            ->whereIn('status', [
                ServiceScopeStatus::Active->value,
                ServiceScopeStatus::Paused->value,
                ServiceScopeStatus::Draft->value,
            ])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'service' => 'An active/paused/draft Service Scope already exists for this Customer and Service.',
            ]);
        }
    }
}
