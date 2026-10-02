<?php

namespace App\Enums\DataPool;

enum IntegrityAuditStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
