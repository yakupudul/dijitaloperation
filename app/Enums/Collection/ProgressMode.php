<?php

namespace App\Enums\Collection;

enum ProgressMode: string
{
    case Counted = 'counted';
    case StageBased = 'stage_based';
    case Indeterminate = 'indeterminate';
    case PageBased = 'page_based';
    case ChunkBased = 'chunk_based';

    public function allowsPercentage(): bool
    {
        return match ($this) {
            self::Counted, self::PageBased, self::ChunkBased => true,
            default => false,
        };
    }
}
