<?php

namespace App\Enums;

enum AssistantCoverageState: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Missing = 'missing';
    case ProviderLimited = 'provider_limited';
    case NotApplicable = 'not_applicable';
}
