<?php

namespace App\Enums\Observability;

/**
 * Bounded operational alert rule types — not Finding Rules.
 * No arbitrary SQL/PHP expressions.
 */
enum OperationalAlertRuleType: string
{
    case CollectionRepeatedFailure = 'COLLECTION_REPEATED_FAILURE';
    case CollectionStuck = 'COLLECTION_STUCK';
    case DatasetStale = 'DATASET_STALE';
    case DatasetBlocked = 'DATASET_BLOCKED';
    case QueueBacklog = 'QUEUE_BACKLOG';
    case QueueWorkerUnavailable = 'QUEUE_WORKER_UNAVAILABLE';
    case SchedulerDispatcherMissing = 'SCHEDULER_DISPATCHER_MISSING';
    case SchedulerLag = 'SCHEDULER_LAG';
    case ProviderAuthFailure = 'PROVIDER_AUTH_FAILURE';
    case ProviderRateLimited = 'PROVIDER_RATE_LIMITED';
    case ProviderErrorRate = 'PROVIDER_ERROR_RATE';
    case ProviderQuotaLow = 'PROVIDER_QUOTA_LOW';
    case ReportDeliveryFailure = 'REPORT_DELIVERY_FAILURE';
    case AiProviderFailureRate = 'AI_PROVIDER_FAILURE_RATE';
}
