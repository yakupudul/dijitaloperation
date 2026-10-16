<?php

namespace App\Services\Work;

use App\Jobs\SendDailyBatchJob;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Gbp\GbpPostReview;
use App\Services\Repair\RepairAutopilot;
use App\Services\Repair\RepairDesk;
use Illuminate\Support\Facades\Cache;

/**
 * Günlük gönderim (yakup, 2026-10-14, "Günde 1 tık"): what the night prepared and AI checked — dated articles, the next
 * Business Profile posts and desk fixes — waits for one click of the Admin. The click only queues SendDailyBatchJob
 * (writes queue): articles go to WordPress as dated drafts, posts are approved for their day, desk rows go through the
 * desk's own approval. What the check held back is listed with its reason and never goes with the click.
 */
final class DailySend
{
    public const string LAST_KEY = 'daily-send:last';

    public const string RUNNING_KEY = 'daily-send:running';

    public function __construct(private readonly ContentAutopilot $content, private readonly GbpPostReview $posts, private readonly RepairAutopilot $repair) {}

    /**
     * @return array{content: int, content_preparing: int, posts: int, repair: int, held: list<array{area: string, title: string, reason: string}>, held_total: int}
     */
    public function summary(): array
    {
        $held = [
            ...$this->content->held()->map(fn (Suggestion $s): array => ['area' => 'İçerik', 'title' => (string) $s->title, 'reason' => (string) data_get($s->action, 'autopilot.review.reason', '')]),
            ...$this->posts->held()->map(fn (GbpQueuedPost $p): array => ['area' => 'Profil gönderisi', 'title' => ($p->digitalAsset?->name ?? '').' · '.$p->publish_on, 'reason' => (string) ($p->review['reason'] ?? '')]),
            ...$this->repair->checked('held')->map(fn (array $r): array => ['area' => RepairDesk::KINDS[$r['kind']] ?? 'Onarım', 'title' => $r['brand'].' · '.$r['title'], 'reason' => (string) ($r['review']['reason'] ?? '')]),
        ];

        return ['content' => $this->content->ready()->count(), 'content_preparing' => $this->content->preparing(), 'posts' => $this->posts->ready()->count(), 'repair' => $this->repair->checked('ok')->count(),
            'held' => array_slice($held, 0, 30), 'held_total' => count($held)];
    }

    /** The Admin's click: everything that passed goes off the web request. */
    public function queue(User $user): void
    {
        Cache::put(self::RUNNING_KEY, now()->toIso8601String(), now()->addMinutes(30));
        SendDailyBatchJob::dispatch((int) $user->id);
    }

    /**
     * @return array{content: array{sent: int, failed: list<string>}, posts: int, repair: array{applied: int, skipped_high: int, failed: list<string>}}
     */
    public function send(User $user): array
    {
        $result = ['content' => $this->content->sendReady($user), 'posts' => $this->posts->approveReady($user), 'repair' => $this->repair->approveReady($user)];
        Cache::put(self::LAST_KEY, $result + ['at' => now()->toIso8601String(), 'by' => $user->name], now()->addDays(7));
        Cache::forget(self::RUNNING_KEY);

        return $result;
    }
}
