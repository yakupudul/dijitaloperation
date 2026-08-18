<?php

namespace App\Enums\Collection;

enum DatasetExecutionOutcome: string
{
    case Continue = 'continue';
    case Completed = 'completed';
    case Retry = 'retry';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
