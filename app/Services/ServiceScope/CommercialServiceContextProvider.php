<?php

namespace App\Services\ServiceScope;

use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\Customer;

/**
 * Stable commercial context query for Prompt 37+ consumers.
 * Does not invent Goals, Findings, or Work.
 */
final class CommercialServiceContextProvider
{
    public function __construct(
        private readonly CustomerServiceScopeReadService $readService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function activeScopesForCustomer(Customer $customer): array
    {
        return array_values(array_filter(
            $this->readService->forCustomer($customer, includeEnded: true),
            static fn (array $row): bool => ($row['status'] ?? null) === ServiceScopeStatus::Active->value
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function applicableScopesForBrand(Brand $brand): array
    {
        return $this->readService->forBrand($brand, includeEnded: false);
    }
}
