<?php

namespace App\Enums;

enum ReportDeliveryStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
