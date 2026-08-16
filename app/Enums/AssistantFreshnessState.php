<?php

namespace App\Enums;

enum AssistantFreshnessState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
