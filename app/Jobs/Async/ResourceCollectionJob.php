<?php

namespace App\Jobs\Async;

use App\Models\ResourceAutomation;
use App\Services\Integrations\ResourceAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ResourceCollectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $automationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('resource-auto:'.$this->automationId))->releaseAfter(30)->expireAfter(600)];
    }

    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(ResourceAutomationService $service): void
    {
        try {
            $service->collect($this->automationId);
        } catch (Throwable $error) {
            // A closed / inaccessible account or a lost consent fails the same way on every retry: stop at once with
            // the real reason instead of retrying and reporting it as an unexpected error.
            $reason = ResourceAutomationService::stopReasonFor($error);
            if ($reason === null) {
                throw $error;
            }
            $service->fail($this->automationId, $reason);
        }
    }

    public function failed(?Throwable $e): void
    {
        if (ResourceAutomation::query()->whereKey($this->automationId)->where('collection_status', 'planning')->exists()) {
            app(ResourceAutomationService::class)->fail($this->automationId);
        }
    }
}
