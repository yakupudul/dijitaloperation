<?php

namespace App\Enums;

enum RecurringScheduleKind: string
{
    case Collection = 'collection';
    case RecurringReview = 'recurring_review';
    case BusinessOutcomeRecheck = 'business_outcome_recheck';
    case InternalNotification = 'internal_notification';
    case ReportDelivery = 'report_delivery';
}
