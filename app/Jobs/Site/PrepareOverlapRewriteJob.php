<?php

namespace App\Jobs\Site;

use App\Models\Suggestion;
use App\Models\User;
use App\Services\Site\OverlapAutopilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * "Ayrıştır" for one cluster overlap: the AI writes the page's refocused version (OverlapAutopilot::prepare). Runs in
 * the name of the Admin who decided, so the AI budget treats it as the Admin's work, not automatic work.
 */
final class PrepareOverlapRewriteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $suggestionId, public int $userId) {}

    public function handle(OverlapAutopilot $autopilot): void
    {
        $suggestion = Suggestion::query()->find($this->suggestionId);
        $user = User::query()->find($this->userId);
        if ($suggestion === null || data_get($suggestion->action, 'rewrite.state') !== 'preparing') {
            return;
        }
        if ($user !== null) {
            Auth::setUser($user);
        }
        $autopilot->prepare($suggestion);
    }

    public function failed(?Throwable $exception): void
    {
        $suggestion = Suggestion::query()->find($this->suggestionId);
        if ($suggestion !== null && data_get($suggestion->action, 'rewrite.state') === 'preparing') {
            $action = (array) $suggestion->action;
            $action['rewrite'] = array_merge((array) $action['rewrite'], ['state' => 'needs_claude', 'message' => mb_substr('İş yarıda kaldı: '.(string) $exception?->getMessage(), 0, 300)]);
            $suggestion->forceFill(['action' => $action])->save();
        }
    }
}
