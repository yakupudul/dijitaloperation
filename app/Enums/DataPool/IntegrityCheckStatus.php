<?php

namespace App\Enums\DataPool;

enum IntegrityCheckStatus: string
{
    case Pass = 'PASS';
    case PassWithLimitation = 'PASS_WITH_LIMITATION';
    case Warning = 'WARNING';
    case Fail = 'FAIL';
    case Unverified = 'UNVERIFIED';
    case NotApplicable = 'NOT_APPLICABLE';

    public function isBlocking(): bool
    {
        return $this === self::Fail || $this === self::Unverified;
    }
}
