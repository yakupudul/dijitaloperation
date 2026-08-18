<?php

namespace App\Support\BrandExperiences;

use InvalidArgumentException;

/**
 * Versioned, bounded context snapshot — not an EAV truth store.
 *
 * @phpstan-type SnapshotArray array{
 *     schema_version: string,
 *     brand_id: int,
 *     customer_id: int,
 *     digital_asset_id: int|null,
 *     subject: string|null,
 *     service_codes: list<string>,
 *     target_audience: string|null,
 *     notes: string|null
 * }
 */
final class BrandExperienceContextSnapshot
{
    public const string SCHEMA_VERSION = 'brand_experience_context_v1';

    /**
     * @param  list<string>  $serviceCodes
     */
    public function __construct(
        public readonly int $brandId,
        public readonly int $customerId,
        public readonly ?int $digitalAssetId = null,
        public readonly ?string $subject = null,
        public readonly array $serviceCodes = [],
        public readonly ?string $targetAudience = null,
        public readonly ?string $notes = null,
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->brandId <= 0 || $this->customerId <= 0) {
            throw new InvalidArgumentException('Context snapshot requires positive brand and customer ids.');
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported context schema version.');
        }

        foreach ($this->serviceCodes as $code) {
            if (! is_string($code) || $code === '') {
                throw new InvalidArgumentException('service_codes must be non-empty strings.');
            }
        }

        if ($this->subject !== null && mb_strlen($this->subject) > 500) {
            throw new InvalidArgumentException('subject exceeds 500 characters.');
        }

        if ($this->notes !== null && mb_strlen($this->notes) > 1000) {
            throw new InvalidArgumentException('notes exceeds 1000 characters.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $allowed = ['schema_version', 'brand_id', 'customer_id', 'digital_asset_id', 'subject', 'service_codes', 'target_audience', 'notes'];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Arbitrary context key [{$key}] is forbidden.");
            }
        }

        $services = $input['service_codes'] ?? [];
        if (! is_array($services)) {
            throw new InvalidArgumentException('service_codes must be a list.');
        }

        return new self(
            brandId: (int) ($input['brand_id'] ?? 0),
            customerId: (int) ($input['customer_id'] ?? 0),
            digitalAssetId: isset($input['digital_asset_id']) ? (int) $input['digital_asset_id'] : null,
            subject: isset($input['subject']) ? (string) $input['subject'] : null,
            serviceCodes: array_values(array_map('strval', $services)),
            targetAudience: isset($input['target_audience']) ? (string) $input['target_audience'] : null,
            notes: isset($input['notes']) ? (string) $input['notes'] : null,
            schemaVersion: (string) ($input['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    /**
     * @return SnapshotArray
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'brand_id' => $this->brandId,
            'customer_id' => $this->customerId,
            'digital_asset_id' => $this->digitalAssetId,
            'subject' => $this->subject,
            'service_codes' => array_values($this->serviceCodes),
            'target_audience' => $this->targetAudience,
            'notes' => $this->notes,
        ];
    }
}
