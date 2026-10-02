<?php

namespace App\Enums\IntelligenceCore;

enum IntelligenceSamplingState: string
{
    case None = 'none';
    case Sampled = 'sampled';
    case Thresholded = 'thresholded';
    case ProviderLimited = 'provider_limited';
    case Unknown = 'unknown';
}
