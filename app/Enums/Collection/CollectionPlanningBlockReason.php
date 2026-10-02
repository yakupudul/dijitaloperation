<?php

namespace App\Enums\Collection;

enum CollectionPlanningBlockReason: string
{
    case CollectionDisabled = 'COLLECTION_DISABLED';
    case CredentialInvalid = 'CREDENTIAL_INVALID';
    case ResourceUnbound = 'RESOURCE_UNBOUND';
    case DatasetUnsupported = 'DATASET_UNSUPPORTED';
    case PolicyNotConfigured = 'POLICY_NOT_CONFIGURED';
    case IntegrityBlocked = 'INTEGRITY_BLOCKED';
    case ActiveCompatibleRun = 'ACTIVE_COMPATIBLE_RUN';
    case ProviderLimited = 'PROVIDER_LIMITED';
    case NoSafeInterval = 'NO_SAFE_INTERVAL';
    case AuthorizationNotReady = 'AUTHORIZATION_NOT_READY';
    case SchedulePaused = 'SCHEDULE_PAUSED';
    case ActionRequired = 'ACTION_REQUIRED';
}
