<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\Contracts\RetryPolicy;
use BackedEnum;

final class DefaultRetryPolicy implements RetryPolicy
{
    public function shouldRetry(
        CollectionDatasetRun $datasetRun,
        CollectionErrorCategory $category,
        int $attemptNumber,
    ): bool {
        $isGoogleAds = $this->isGoogleAds($datasetRun);
        $maxAttempts = (int) $datasetRun->max_attempts;
        if ($isGoogleAds) {
            $maxAttempts = max(
                $maxAttempts,
                (int) config('moxdop-google-ads-collector.retry_max_attempts', 7),
            );
        }

        if ($attemptNumber >= $maxAttempts) {
            return false;
        }

        if ($isGoogleAds && $category === CollectionErrorCategory::Quota) {
            return true;
        }

        return match ($category) {
            CollectionErrorCategory::Timeout,
            CollectionErrorCategory::Network,
            CollectionErrorCategory::Provider5xx,
            CollectionErrorCategory::RateLimit => true,
            CollectionErrorCategory::Quota,
            CollectionErrorCategory::Authentication,
            CollectionErrorCategory::Authorization,
            CollectionErrorCategory::InvalidRequest,
            CollectionErrorCategory::ContractMismatch,
            CollectionErrorCategory::UnimplementedCapability,
            CollectionErrorCategory::Cancelled,
            CollectionErrorCategory::Normalization,
            CollectionErrorCategory::Persistence,
            CollectionErrorCategory::Unknown => false,
        };
    }

    public function backoffSeconds(CollectionDatasetRun $datasetRun, int $attemptNumber): int
    {
        if ($this->isGoogleAds($datasetRun)) {
            /** @var list<int> $schedule */
            $schedule = config('moxdop-google-ads-collector.retry_backoff_seconds', [10, 20, 40, 80, 160, 300]);
            $index = max(0, $attemptNumber - 1);
            $base = (int) ($schedule[min($index, max(0, count($schedule) - 1))] ?? 10);
            $jitterMax = max(0, (int) config('moxdop-google-ads-collector.retry_jitter_seconds', 5));

            if ($jitterMax === 0) {
                return max(1, $base);
            }

            $seed = hash('sha256', (string) $datasetRun->id.'|'.$attemptNumber);
            $jitter = (int) (hexdec(substr($seed, 0, 8)) % ($jitterMax + 1));

            return max(1, $base + $jitter);
        }

        /** @var list<int> $schedule */
        $schedule = config('moxdop-collection.default_backoff_seconds', [30, 90, 180]);
        $index = max(0, $attemptNumber - 1);

        return $schedule[min($index, count($schedule) - 1)] ?? 30;
    }

    private function isGoogleAds(CollectionDatasetRun $datasetRun): bool
    {
        $provider = $datasetRun->provider_or_source;
        if ($provider instanceof BackedEnum) {
            $provider = $provider->value;
        }

        return strtoupper((string) $provider) === 'GOOGLE_ADS';
    }
}
