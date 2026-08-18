<?php

namespace App\Enums;

enum QaReviewStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
