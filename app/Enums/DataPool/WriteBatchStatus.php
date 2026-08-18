<?php

namespace App\Enums\DataPool;

enum WriteBatchStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case Failed = 'failed';
}
