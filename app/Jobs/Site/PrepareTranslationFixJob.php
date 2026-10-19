<?php

namespace App\Jobs\Site;

use App\Models\Suggestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\BrokenTranslations;
use App\Services\Site\OverlapAutopilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Bozuk çeviri onarımı: the AI writes one broken page's faithful translation (BrokenTranslations::prepare), in the
 * Admin's name. While Claude (MCP) has not answered, the same job runs again with the answer.
 */
final class PrepareTranslationFixJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $suggestionId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(BrokenTranslations $translations): void
    {
        $suggestion = Suggestion::query()->find($this->suggestionId);
        if ($suggestion === null || $suggestion->action_type !== BrokenTranslations::TYPE || data_get($suggestion->action, 'proposal') !== null) {
            return;
        }
        if (($admin = OverlapAutopilot::admin()) !== null) {
            Auth::setUser($admin);
        }
        $tasks = app(AiTaskQueue::class);
        $tasks->begin(new self($this->suggestionId), (int) $suggestion->brand_id, 'Bozuk çeviri · öneri #'.$suggestion->id);
        try {
            $result = $translations->prepare($suggestion);
            if (! in_array($result['status'], ['ready', 'queued'], true)) {
                $fresh = $suggestion->fresh() ?? $suggestion;
                $fresh->forceFill(['action' => array_merge((array) $fresh->action, ['prepare_error' => mb_substr((string) ($result['message'] ?? $result['status']), 0, 200)])])->save();
            }
        } finally {
            $tasks->settle();
        }
    }
}
