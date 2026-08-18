<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Decided = 'decided';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this === self::Decided || $this === self::Cancelled;
    }
}
