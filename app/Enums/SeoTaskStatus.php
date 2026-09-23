<?php

namespace App\Enums;

enum SeoTaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Skipped = 'skipped';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::Done => 'Yapıldı',
            self::Skipped => 'Atlandı',
            self::Stale => 'Geçersiz',
        };
    }
}
