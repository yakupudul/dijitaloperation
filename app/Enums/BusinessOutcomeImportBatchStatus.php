<?php

namespace App\Enums;

enum BusinessOutcomeImportBatchStatus: string
{
    case Validated = 'validated';
    case Committed = 'committed';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
