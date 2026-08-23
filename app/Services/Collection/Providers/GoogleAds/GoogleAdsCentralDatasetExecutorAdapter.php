<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\RequirementLevel;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Compatibility boundary for the central Google Ads executor.
 *
 * The monthly lifetime-history family is handled by its dedicated implementation
 * here, while all existing central families continue through the stable executor.
 */
final class GoogleAdsCentralDatasetExecutorAdapter implements DatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsCentralDatasetExecutor $inner,
        private readonly GoogleAdsHistoricalDatasetExecutor $history,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return $this->inner->supportedRequestFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        if (GoogleAdsCentralRequestFamilyCatalog::isHistoryFamily($context->datasetRun->request_family_id)) {
            return $this->history->execute($context);
        }

        $context->datasetRun->mergeCasts(['requirement_level' => 'string']);
        $originalMetadata = is_array($context->datasetRun->metadata)
            ? $context->datasetRun->metadata
            : [];

        try {
            if (GoogleAdsCentralRequestFamilyCatalog::isChangeEvent($context->datasetRun->request_family_id)) {
                $this->clampChangeEventRange($context);
            }

            return $this->inner->execute($context);
        } finally {
            $context->datasetRun->setAttribute('metadata', $originalMetadata);
            $context->datasetRun->mergeCasts(['requirement_level' => RequirementLevel::class]);
        }
    }

    private function clampChangeEventRange(DatasetExecutionContext $context): void
    {
        $metadata = is_array($context->datasetRun->metadata)
            ? $context->datasetRun->metadata
            : [];
        $range = $metadata['date_range'] ?? null;
        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return;
        }

        $timezone = (string) data_get($context->resourceRun->metadata, 'time_zone', 'UTC');
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        try {
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $oldestSafeStart = $today->subDays(29);
            $closedEnd = $today->subDay();
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start'], $timezone)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end'], $timezone)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($start->lessThan($oldestSafeStart)) {
            $start = $oldestSafeStart;
        }
        if ($end->greaterThan($closedEnd)) {
            $end = $closedEnd;
        }
        if ($start->greaterThan($end)) {
            $start = $end;
        }

        $metadata['date_range'] = [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
        $context->datasetRun->setAttribute('metadata', $metadata);
    }
}
