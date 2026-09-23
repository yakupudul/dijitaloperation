<?php

namespace App\Enums;

enum RecurringScheduleKind: string
{
    case Collection = 'collection';
    /** @deprecated Faz 1: producer removed */
    case RecurringReview = 'recurring_review';
    /** @deprecated Faz 1: producer removed */
    case BusinessOutcomeRecheck = 'business_outcome_recheck';
    case InternalNotification = 'internal_notification';
    case ReportDelivery = 'report_delivery';
    case IntelligenceValidityRecheck = 'intelligence_validity_recheck';
}
