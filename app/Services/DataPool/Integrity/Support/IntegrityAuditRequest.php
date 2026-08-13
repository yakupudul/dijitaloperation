<?php

namespace App\Services\DataPool\Integrity\Support;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Models\User;

final class IntegrityAuditRequest
{
    /**
     * @param  list<string>|null  $providers
     * @param  list<string>|null  $datasetIds
     * @param  list<int>|null  $digitalAssetIds
     * @param  list<int>|null  $externalResourceIds
     */
    public function __construct(
        public readonly IntegrityAuditMode $mode = IntegrityAuditMode::LocalIntegrity,
        public readonly ?array $providers = null,
        public readonly ?array $datasetIds = null,
        public readonly ?array $digitalAssetIds = null,
        public readonly ?array $externalResourceIds = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?User $initiatedBy = null,
        public readonly bool $persistResults = true,
    ) {}
}
