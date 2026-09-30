<?php

namespace App\Services\Ai;

use App\Services\AiJobs\AiJobTracker;

/**
 * Cooperative stop of a running AI job (AI işleri › Durdur): a multi-call job calls `AiCancellation::throwIfRequested()`
 * between its AI calls; when the operator asked to stop the job being processed it throws AiCancelledException, which
 * ends the job quietly (the queue job completes, the row becomes "Durduruldu"). Outside a tracked job it does nothing.
 */
final class AiCancellation
{
    /** @throws AiCancelledException */
    public static function throwIfRequested(): void
    {
        if (self::requested()) {
            throw new AiCancelledException;
        }
    }

    /** Whether the operator asked to stop the AI job this process is running. */
    public static function requested(): bool
    {
        return app(AiJobTracker::class)->cancelRequested();
    }
}
