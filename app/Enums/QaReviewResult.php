<?php

namespace App\Enums;

enum QaReviewResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NeedsChanges = 'needs_changes';
}
