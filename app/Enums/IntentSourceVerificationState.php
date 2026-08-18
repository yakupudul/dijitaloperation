<?php

namespace App\Enums;

enum IntentSourceVerificationState: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Unreachable = 'unreachable';
}
