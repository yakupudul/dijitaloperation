<?php

namespace App\Enums;

/**
 * Lifecycle for Sector Learning artifacts / revisions.
 */
enum SectorLearningArtifactStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Stale = 'stale';
    case PrivacyBlocked = 'privacy_blocked';
    case Invalidated = 'invalidated';

    public function isConsumerEligible(): bool
    {
        return $this === self::Active;
    }
}
