<?php

namespace App\Services\DataPool\Integrity\Support;

use App\Enums\DataPool\IntegrityCheckStatus;

final class IntegrityCheckOutcome
{
    /**
     * @param  array<string, mixed>|null  $expected
     * @param  array<string, mixed>|null  $observed
     * @param  array<string, mixed>|null  $difference
     * @param  array<string, mixed>|null  $tolerance
     * @param  array<string, mixed>|null  $evidence
     */
    public function __construct(
        public readonly string $checkId,
        public readonly string $category,
        public readonly IntegrityCheckStatus $status,
        public readonly string $severity = 'info',
        public readonly ?string $message = null,
        public readonly ?array $expected = null,
        public readonly ?array $observed = null,
        public readonly ?array $difference = null,
        public readonly ?array $tolerance = null,
        public readonly ?array $evidence = null,
        public readonly bool $blocksMigration = false,
        public readonly ?string $providerOrSource = null,
        public readonly ?string $datasetId = null,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
    ) {}

    public static function pass(
        string $checkId,
        string $category,
        string $message,
        ?array $evidence = null,
        ?string $provider = null,
        ?string $datasetId = null,
        ?int $assetId = null,
        ?int $resourceId = null,
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::Pass,
            severity: 'info',
            message: $message,
            evidence: $evidence,
            blocksMigration: false,
            providerOrSource: $provider,
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
        );
    }

    public static function fail(
        string $checkId,
        string $category,
        string $message,
        bool $blocksMigration = true,
        ?array $expected = null,
        ?array $observed = null,
        ?array $difference = null,
        ?array $evidence = null,
        ?string $provider = null,
        ?string $datasetId = null,
        ?int $assetId = null,
        ?int $resourceId = null,
        string $severity = 'critical',
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::Fail,
            severity: $severity,
            message: $message,
            expected: $expected,
            observed: $observed,
            difference: $difference,
            evidence: $evidence,
            blocksMigration: $blocksMigration,
            providerOrSource: $provider,
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
        );
    }

    public static function limitation(
        string $checkId,
        string $category,
        string $message,
        ?array $evidence = null,
        ?string $provider = null,
        ?string $datasetId = null,
        ?int $assetId = null,
        ?int $resourceId = null,
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::PassWithLimitation,
            severity: 'info',
            message: $message,
            evidence: $evidence,
            blocksMigration: false,
            providerOrSource: $provider,
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
        );
    }

    public static function warning(
        string $checkId,
        string $category,
        string $message,
        ?array $evidence = null,
        ?string $provider = null,
        ?string $datasetId = null,
        ?int $assetId = null,
        ?int $resourceId = null,
        bool $blocksMigration = false,
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::Warning,
            severity: 'warning',
            message: $message,
            evidence: $evidence,
            blocksMigration: $blocksMigration,
            providerOrSource: $provider,
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
        );
    }

    public static function unverified(
        string $checkId,
        string $category,
        string $message,
        bool $blocksMigration = true,
        ?string $provider = null,
        ?string $datasetId = null,
        ?int $assetId = null,
        ?int $resourceId = null,
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::Unverified,
            severity: 'warning',
            message: $message,
            blocksMigration: $blocksMigration,
            providerOrSource: $provider,
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
        );
    }

    public static function notApplicable(
        string $checkId,
        string $category,
        string $message,
        ?string $provider = null,
        ?string $datasetId = null,
    ): self {
        return new self(
            checkId: $checkId,
            category: $category,
            status: IntegrityCheckStatus::NotApplicable,
            severity: 'info',
            message: $message,
            blocksMigration: false,
            providerOrSource: $provider,
            datasetId: $datasetId,
        );
    }
}
