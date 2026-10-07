<?php

namespace App\Jobs;

use App\Models\BrandSetupProposal;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\BrandSetup\BrandSetupAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Builds one "Otomatik kur" proposal (GA4 stream lookups + one AI call) off the request path; delegated: runs again with Claude's answer. */
final class BuildBrandSetupProposalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $proposalId) {}

    public function handle(BrandSetupAssistant $assistant, AiTaskQueue $tasks, BrandAutofill $autofill): void
    {
        $proposal = BrandSetupProposal::query()->find($this->proposalId);
        $tasks->begin(new self($this->proposalId), $proposal?->brand_id, 'Otomatik kur · hizmet önerisi');
        try {
            $built = $assistant->build($this->proposalId);
        } finally {
            $tasks->settle();
        }
        // Marka tamamlama: a run Claude started itself is used at once (yakup, 2026-10-07 "Hemen kullan").
        if ($built->auto_apply && $built->status === BrandSetupProposal::STATUS_READY) {
            $autofill->apply($built);
        }
    }

    public function failed(?Throwable $exception): void
    {
        BrandSetupProposal::query()->whereKey($this->proposalId)
            ->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->update(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => mb_substr((string) $exception?->getMessage(), 0, 500)]);
    }
}
