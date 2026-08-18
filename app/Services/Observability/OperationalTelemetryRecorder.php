<?php

namespace App\Services\Observability;

use App\Support\Observability\OperationalContext;
use App\Support\Security\SecurityRedactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structured operational logging boundary (Prompt 66).
 * Business operations must not fail solely because logging fails.
 */
final class OperationalTelemetryRecorder
{
    public function __construct(
        private readonly SecurityRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $event, array $context): void
    {
        if (! config('moxdop-observability.enabled', true)) {
            return;
        }

        try {
            $safe = $this->redactor->redactContext($context);
            // Ensure OperationalContext allowlist keys are present when provided.
            $safe = array_merge(OperationalContext::make($context), $safe);
            Log::{$level}('ops.'.$event, $safe);
        } catch (Throwable) {
            // Observability failure must not break business path.
        }
    }
}
