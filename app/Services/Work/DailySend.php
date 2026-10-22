<?php

namespace App\Services\Work;

use App\Jobs\SendDailyBatchJob;
use App\Models\DigitalAsset;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WorkBatch;
use App\Services\Gbp\GbpPostReview;
use App\Services\Repair\RepairAutopilot;
use App\Services\Repair\RepairDesk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    /** Live progress of the running send (the strip polls it): per part `[done, total]`, set by queue() and send(). */
    public const string PROGRESS_KEY = 'daily-send:progress';

    public function __construct(private readonly ContentAutopilot $content, private readonly GbpPostReview $posts, private readonly RepairAutopilot $repair) {}

    /**
     * `no_connector`: checked articles of sites without a paired WordPress Connector, one line per site; they are not
     * counted as ready and the click does not try them (yakup 2026-10-19: one note instead of a failure per article).
     *
     * @return array{content: int, content_preparing: int, posts: int, repair: int, held: list<array{area: string, title: string, reason: string}>, held_total: int, no_connector: list<array{site: string, count: int}>}
     */
    public function summary(): array
    {
        $paired = ContentAutopilot::pairedSiteIds();
        [$content, $waiting] = $this->content->ready()->partition(fn (Suggestion $s): bool => in_array((int) data_get($s->action, 'site_id'), $paired, true));
        $sites = DigitalAsset::query()->whereIn('id', $waiting->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))->unique())->pluck('name', 'id');
        $noConnector = $waiting->countBy(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))
            ->map(fn (int $count, int $siteId): array => ['site' => (string) ($sites[$siteId] ?? 'Site'), 'count' => $count])->values()->all();

        $repair = $this->repair->checkedBoth();
        $held = [
            ...$this->content->held()->map(fn (Suggestion $s): array => ['area' => 'İçerik', 'title' => (string) $s->title, 'reason' => (string) data_get($s->action, 'autopilot.review.reason', '')]),
            ...$this->posts->held()->map(fn (GbpQueuedPost $p): array => ['area' => 'Profil gönderisi', 'title' => ($p->digitalAsset?->name ?? '').' · '.$p->publish_on, 'reason' => (string) ($p->review['reason'] ?? '')]),
            ...$repair['held']->map(fn (array $r): array => ['area' => RepairDesk::KINDS[$r['kind']] ?? 'Onarım', 'title' => $r['brand'].' · '.$r['title'], 'reason' => (string) ($r['review']['reason'] ?? '')]),
        ];

        return ['content' => $content->count(), 'content_preparing' => $this->content->preparing(), 'posts' => $this->posts->ready()->count(), 'repair' => $repair['ok']->count(),
            'held' => array_slice($held, 0, 30), 'held_total' => count($held), 'no_connector' => $noConnector];
    }

    /**
     * The Admin's click: everything that passed goes off the web request. `$totals` (the strip's ready counts) start
     * the progress the strip shows while the job runs.
     *
     * @param  array{content?: int, posts?: int, repair?: int}  $totals
     */
    public function queue(User $user, array $totals = []): void
    {
        Cache::put(self::RUNNING_KEY, now()->toIso8601String(), now()->addMinutes(30));
        $this->progress(['content' => [0, (int) ($totals['content'] ?? 0)], 'posts' => [0, (int) ($totals['posts'] ?? 0)], 'repair' => [0, (int) ($totals['repair'] ?? 0)]]);
        $batch = app(WorkBatches::class)->open(WorkBatch::KIND_DAILY, 'Günlük gönderim', $user, array_sum(array_map('intval', $totals)), ['channel' => 'mixed']);
        SendDailyBatchJob::dispatch((int) $user->id, $batch);
    }

    /**
     * The running send's progress: per part (content, posts, repair) `[done, total]`, null when nothing runs.
     *
     * @return array{content: array{0: int, 1: int}, posts: array{0: int, 1: int}, repair: array{0: int, 1: int}, done: int, total: int}|null
     */
    public static function currentProgress(): ?array
    {
        $state = Cache::get(self::PROGRESS_KEY);
        if (! is_array($state)) {
            return null;
        }
        $parts = [];
        foreach (['content', 'posts', 'repair'] as $part) {
            [$done, $total] = array_map('intval', array_pad(array_values((array) ($state[$part] ?? [])), 2, 0));
            $parts[$part] = [$done, max($done, $total)];
        }

        return $parts + ['done' => array_sum(array_column($parts, 0)), 'total' => array_sum(array_column($parts, 1))];
    }

    /** @param  array<string, array{0: int, 1: int}>  $parts  merged into the stored progress */
    private function progress(array $parts): void
    {
        $state = Cache::get(self::PROGRESS_KEY);
        Cache::put(self::PROGRESS_KEY, array_replace(is_array($state) ? $state : [], $parts), now()->addMinutes(30));
    }

    public static function clearProgress(): void
    {
        Cache::forget(self::PROGRESS_KEY);
    }

    /**
     * @return array{content: array{sent: int, failed: list<string>}, posts: int, repair: array{applied: int, skipped_high: int, failed: list<string>}}
     */
    public function send(User $user, ?int $batchId = null): array
    {
        $batches = app(WorkBatches::class);
        $batches->start($batchId);
        // Same parts in the same order as before; each step only writes how far it got (the strip polls it).
        [$content, $posts, $repair] = WorkBatches::within($batchId, function () use ($user): array {
            $content = $this->content->sendReady($user, null, fn (int $done, int $total) => $this->progress(['content' => [$done, $total]]));
            $sentContent = $content['sent'] + count($content['failed']);
            $this->progress(['content' => [$sentContent, $sentContent]]);
            $posts = $this->posts->approveReady($user);
            $this->progress(['posts' => [$posts, $posts]]);
            $repair = $this->repair->approveReady($user);
            $repairDone = $repair['applied'] + $repair['skipped_high'] + count($repair['failed']);
            $this->progress(['repair' => [$repairDone, $repairDone]]);

            return [$content, $posts, $repair];
        });
        $result = ['content' => $content, 'posts' => $posts, 'repair' => $repair];
        // Akış: one row for the click, the parts that did not go listed under it.
        $failures = array_map(fn (string $line): array => ['title' => Str::before($line, ':'), 'reason' => trim(Str::after($line, ':')) ?: $line],
            [...array_map('strval', $content['failed']), ...array_map('strval', $repair['failed'])]);
        $batches->step($batchId, 0, $failures);
        $batches->finish($batchId, null, $content['sent'] + $posts + $repair['applied']);
        Cache::put(self::LAST_KEY, $result + ['at' => now()->toIso8601String(), 'by' => $user->name], now()->addDays(7));
        Cache::forget(self::RUNNING_KEY);
        self::clearProgress();

        return $result;
    }
}
