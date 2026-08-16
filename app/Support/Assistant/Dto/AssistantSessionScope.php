<?php

namespace App\Support\Assistant\Dto;

/**
 * Explicit authorized Assistant session scope (Prompt 56).
 * Conversation text cannot change authorization.
 */
final class AssistantSessionScope
{
    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @param  list<int>  $authorizedDigitalAssetIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $authorizedCustomerIds,
        public readonly array $authorizedBrandIds = [],
        public readonly array $authorizedDigitalAssetIds = [],
        public readonly ?int $customerId = null,
        public readonly ?int $brandId = null,
        public readonly ?int $digitalAssetId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $locale = null,
        public readonly ?string $timezone = null,
    ) {}

    public function hasCustomer(): bool
    {
        return $this->customerId !== null;
    }

    public function hasBrand(): bool
    {
        return $this->brandId !== null;
    }

    public function hasDigitalAsset(): bool
    {
        return $this->digitalAssetId !== null;
    }

    public function customerAuthorized(): bool
    {
        return $this->customerId !== null
            && in_array($this->customerId, $this->authorizedCustomerIds, true);
    }

    public function brandAuthorized(): bool
    {
        return $this->brandId !== null
            && in_array($this->brandId, $this->authorizedBrandIds, true);
    }

    public function digitalAssetAuthorized(): bool
    {
        return $this->digitalAssetId === null
            || in_array($this->digitalAssetId, $this->authorizedDigitalAssetIds, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'authorized_customer_ids' => $this->authorizedCustomerIds,
            'authorized_brand_ids' => $this->authorizedBrandIds,
            'authorized_digital_asset_ids' => $this->authorizedDigitalAssetIds,
            'first_customer_fallback' => false,
            'first_brand_fallback' => false,
            'first_asset_fallback' => false,
        ];
    }
}
