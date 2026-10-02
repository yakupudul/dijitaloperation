<?php

namespace App\Enums;

enum OpportunityDetectionState: string
{
    case Detected = 'detected';
    case NoLongerDetected = 'no_longer_detected';
    case BlockedInput = 'blocked_input';
}
