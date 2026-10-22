<?php

use App\Jobs\Gbp\FillGbpPostQueueJob;
use App\Models\AiTask;
use App\Models\GbpQueuedPost;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * İşletme Profili gönderi işleri kendini çoğaltıyordu (2026-10-22, Avrupadent Buca 7 kez): every rebuilt pack of a
 * profile was a new Claude task while the old ones waited on. The waiting copies are dropped and each profile is
 * planned once more with the frozen pack (GbpPostQueue::frozenPlan); drafts of one profile that repeat a page and
 * angle, or another draft's text, are skipped (the earliest stays). Nothing is sent anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_tasks') || ! Schema::hasTable('gbp_queued_posts')) {
            return;
        }
        $assets = [];
        AiTask::query()->where('operation', 'gbp.post_queue')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->get(['id', 'resume'])
            ->each(function (AiTask $task) use (&$assets): void {
                try {
                    $job = unserialize((string) $task->resume);
                    if ($job instanceof FillGbpPostQueueJob) {
                        $assets[$job->assetId] = true;
                    }
                } catch (Throwable) {
                    // An unreadable payload is simply dropped with the others.
                }
            });
        AiTask::query()->where('operation', 'gbp.post_queue')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
            ->update(['status' => AiTask::CONSUMED, 'consumed_at' => now()]);

        GbpQueuedPost::query()->where('status', GbpQueuedPost::DRAFT)->orderBy('publish_on')->orderBy('id')
            ->get(['id', 'digital_asset_id', 'page_id', 'angle', 'summary'])->groupBy('digital_asset_id')
            ->each(function ($drafts): void {
                $kept = [];
                $skip = [];
                foreach ($drafts as $draft) {
                    $twin = collect($kept)->first(fn (GbpQueuedPost $k): bool => ($k->page_id === $draft->page_id && $k->angle === $draft->angle)
                        || GbpPostQueue::similarity((string) $k->summary, (string) $draft->summary) > GbpPostQueue::SIMILARITY_MAX);
                    if ($twin !== null) {
                        $skip[] = $draft->id;
                    } else {
                        $kept[] = $draft;
                    }
                }
                if ($skip !== []) {
                    GbpQueuedPost::query()->whereIn('id', $skip)->update(['status' => GbpQueuedPost::SKIPPED]);
                }
            });

        foreach (array_keys($assets) as $assetId) {
            FillGbpPostQueueJob::dispatch((int) $assetId);
        }
    }

    public function down(): void {}
};
