<?php

namespace App\Jobs\Site;

use App\Models\ServiceCategory;
use App\Services\Compliance\ForbiddenTermsLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Yasaklı ifadeler "AI ile öner": one `compliance.forbidden_terms` call; candidates wait in the sector's cache key. */
final class SuggestForbiddenTermsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public int $sectorId) {}

    public function handle(ForbiddenTermsLibrary $library): void
    {
        $sector = ServiceCategory::query()->find($this->sectorId);
        Cache::put(ForbiddenTermsLibrary::cacheKey($this->sectorId), $sector !== null ? $library->suggest($sector) : ['status' => 'error', 'items' => []], now()->addDays(2));
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(ForbiddenTermsLibrary::cacheKey($this->sectorId), ['status' => 'error', 'items' => []], now()->addDays(2));
    }
}
