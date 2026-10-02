<?php

namespace App\Jobs;

use App\Models\BrandSetupProposal;
use App\Services\BrandSetup\BrandSetupAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Builds one "Otomatik kur" proposal (GA4 stream lookups + one AI call) off the request path. */
final class BuildBrandSetupProposalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $proposalId) {}

    public function handle(BrandSetupAssistant $assistant): void
    {
        $assistant->build($this->proposalId);
    }

    public function failed(?Throwable $exception): void
    {
        BrandSetupProposal::query()->whereKey($this->proposalId)
            ->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->update(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => mb_substr((string) $exception?->getMessage(), 0, 500)]);
    }
}
