<?php

namespace App\Enums\IntelligenceCore;

enum IdentityResolutionStatus: string
{
    case Provisional = 'provisional';
    case Resolved = 'resolved';
    case Conflicted = 'conflicted';
    case Rejected = 'rejected';
    case Retired = 'retired';
}
