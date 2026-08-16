<?php

namespace App\Enums;

enum RecurringDomainRunType: string
{
    case CollectionRun = 'collection_run';
    case RecurringReviewRun = 'recurring_review_run';
    case BusinessOutcomeRecheckRun = 'business_outcome_recheck_run';
    case NotificationBatch = 'notification_batch';
    case ReportDeliveryOccurrence = 'report_delivery_occurrence';
    case IntelligenceExecutionPlan = 'intelligence_execution_plan';
}
