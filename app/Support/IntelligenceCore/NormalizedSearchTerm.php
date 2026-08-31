<?php

namespace App\Support\IntelligenceCore;

final class NormalizedSearchTerm
{
    public function __construct(
        public readonly string $canonicalText,
        public readonly string $foldedText,
        public readonly string $normalizationVersion,
    ) {}
}
