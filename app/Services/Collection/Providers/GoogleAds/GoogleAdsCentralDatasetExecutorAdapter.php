<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\RequirementLevel;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;

/**
 * Compatibility boundary for the central Google Ads executor.
 *
 * The central executor intentionally tests the stored RequirementLevel backing
 * value when deciding whether a provider-side contract absence is acceptable.
 * Canonical collection models use the RequirementLevel enum everywhere else, so
 * we temporarily expose only this execution context as a string and restore the
 * enum cast before the worker/status aggregator continues.
 */
final class GoogleAdsCentralDatasetExecutorAdapter implements DatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsCentralDatasetExecutor $inner,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return $this->inner->supportedRequestFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $context->datasetRun->mergeCasts(['requirement_level' => 'string']);

        try {
            return $this->inner->execute($context);
        } finally {
            $context->datasetRun->mergeCasts(['requirement_level' => RequirementLevel::class]);
        }
    }
}
