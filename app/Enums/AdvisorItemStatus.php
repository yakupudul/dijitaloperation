<?php

namespace App\Enums;

enum AdvisorItemStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Skipped = 'skipped';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::Done => 'Yapıldı',
            self::Skipped => 'Atlandı',
            self::Resolved => 'Kendiliğinden kapandı',
        };
    }
}
