<?php

namespace App\Enums;

enum ReportDeliveryAttemptResult: string
{
    case Sent = 'sent';
    case FailedTransient = 'failed_transient';
    case FailedPermanent = 'failed_permanent';
    case SkippedAlreadySent = 'skipped_already_sent';
}
