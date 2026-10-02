<?php

namespace App\Enums\DataPool;

enum IntegrityAuditMode: string
{
    case LocalIntegrity = 'LOCAL_INTEGRITY';
    case ProviderReconciliation = 'PROVIDER_RECONCILIATION';
}
