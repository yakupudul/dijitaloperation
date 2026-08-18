<?php

namespace App\Support\Evidence;

use App\Models\Evidence;
use App\Models\Run;

final class CanonicalEvidencePipelineResult
{
    /**
     * @param  list<Evidence>  $written
     * @param  list<array{definition_id: string, report: array<string, mixed>}>  $ineligible
     */
    public function __construct(
        public readonly Run $run,
        public readonly array $written,
        public readonly array $ineligible,
        public readonly int $created,
        public readonly int $updated,
    ) {}
}
