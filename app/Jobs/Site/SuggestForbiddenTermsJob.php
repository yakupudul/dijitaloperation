<?php

namespace App\Jobs\Site;

use App\Models\ServiceCategory;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Compliance\ForbiddenTermsLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Yasaklı ifadeler "AI ile öner": one `compliance.forbidden_terms` call; candidates wait in the sector's cache key. The
 * call can wait for Claude (MCP): the job then runs again once Claude answered.
 */
final class SuggestForbiddenTermsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public int $sectorId) {}

    public function handle(ForbiddenTermsLibrary $library, AiTaskQueue $tasks): void
    {
        $sector = ServiceCategory::query()->find($this->sectorId);
        if ($sector === null) {
            Cache::put(ForbiddenTermsLibrary::cacheKey($this->sectorId), ['status' => 'error', 'items' => []], now()->addDays(2));

            return;
        }
        $tasks->begin(new self($this->sectorId), null, 'Yasaklı ifade önerisi · '.$sector->name);
        try {
            $result = $library->suggest($sector);
        } finally {
            $tasks->settle();
        }
        // Waiting for Claude keeps "AI aday ifadeler hazırlıyor…" until the answer comes back.
        Cache::put(ForbiddenTermsLibrary::cacheKey($this->sectorId), $result['status'] === 'queued' ? ['status' => 'running', 'items' => []] : $result, now()->addDays(2));
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(ForbiddenTermsLibrary::cacheKey($this->sectorId), ['status' => 'error', 'items' => []], now()->addDays(2));
    }
}
