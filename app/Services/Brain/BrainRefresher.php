<?php

namespace App\Services\Brain;

use App\Models\DigitalAsset;
use App\Services\Brain\Chain\AdsChainBuilder;
use App\Services\Brain\Chain\MetaChainBuilder;
use App\Services\Brain\Clustering\CannibalizationDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weekly, AI-free part of the Service Brain: everything that is pure calculation on stored data. Each step
 * runs on its own; one failing step does not stop the others. AI work only happens on an operator click.
 */
final class BrainRefresher
{
    public function __construct(
        private readonly CannibalizationDetector $cannibalization,
        private readonly AdsChainBuilder $adsChain,
        private readonly MetaChainBuilder $metaChain,
    ) {}

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

        $report['ads_chain'] = $this->step(function () use ($brandId): int {
            $count = 0;
            foreach ($this->assets('google_ads', $brandId) as $asset) {
                $count += $this->adsChain->build($asset);
            }

            return $count;
        });
        $report['meta_chain'] = $this->step(function () use ($brandId): int {
            $count = 0;
            foreach ($this->assets('meta_ads', $brandId) as $asset) {
                $count += $this->metaChain->build($asset);
            }

            return $count;
        });

        return $report;
    }

    /** @return iterable<DigitalAsset> */
    private function assets(string $type, ?int $brandId): iterable
    {
        return DigitalAsset::query()->operational()->where('type', $type)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->with('brand')->orderBy('id')->lazy(50);
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
