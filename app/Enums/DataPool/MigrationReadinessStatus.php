<?php

namespace App\Enums\DataPool;

enum MigrationReadinessStatus: string
{
    case ReadyForRealUi = 'READY_FOR_REAL_UI';
    case ReadyWithProviderLimitation = 'READY_WITH_PROVIDER_LIMITATION';
    case BlockedPartial = 'BLOCKED_PARTIAL';
    case BlockedIntegrity = 'BLOCKED_INTEGRITY';
    case BlockedStale = 'BLOCKED_STALE';
    case BlockedContract = 'BLOCKED_CONTRACT';
    case Unverified = 'UNVERIFIED';

    public function allowsRealUiMigration(): bool
    {
        return $this === self::ReadyForRealUi || $this === self::ReadyWithProviderLimitation;
    }
}
