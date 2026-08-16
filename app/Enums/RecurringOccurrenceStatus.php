<?php

namespace App\Enums;

enum RecurringOccurrenceStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
}
