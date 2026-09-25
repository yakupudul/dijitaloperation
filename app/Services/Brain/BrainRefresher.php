<?php

namespace App\Services\Brain;

use App\Models\DigitalAsset;
use App\Services\Brain\Clustering\CannibalizationDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weekly, AI-free part of the Service Brain: everything that is pure calculation on stored data. Each step
 * runs on its own; one failing step does not stop the others. AI work only happens on an operator click.
 */
final class BrainRefresher
{
    public function __construct(private readonly CannibalizationDetector $cannibalization) {}

    /** @return array<string, int|string> step => count or error */
    public function run(?int $brandId = null): array
    {
        $report = [];
        $report['cannibalization'] = $this->step(function () use ($brandId): int {
            $count = 0;
            foreach ($this->websites($brandId) as $site) {
                $count += $this->cannibalization->detect($site);
            }

            return $count;
        });

        return $report;
    }

    /** @return iterable<DigitalAsset> */
    public function websites(?int $brandId = null): iterable
    {
        return DigitalAsset::query()->operational()->where('type', 'website')
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->with('brand')->orderBy('id')->lazy(50);
    }

    private function step(callable $step): int|string
    {
        try {
            return (int) $step();
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Brain refresh step failed.', ['error' => $exception->getMessage()]);

            return 'failed: '.mb_substr($exception->getMessage(), 0, 120);
        }
    }
}
