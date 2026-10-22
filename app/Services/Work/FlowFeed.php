<?php

namespace App\Services\Work;

use App\Livewire\Operator\LiveFeed;
use App\Models\AiLiveOperation;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\WorkBatch;
use App\Services\Repair\RepairDesk;
use App\Support\Ai\AiOperationLabels;
use App\Support\Operator\OperatorClock;
use App\Support\SchemaState;
use App\Support\Work\WorkStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Akış (Aşama 4, 2026-10-22; approved prototype "Komuta ve Akış"): every piece of work of today and everything still
 * open, as rows a person reads — an approval or send of a thousand rows is one row (WorkBatch) that opens to its
 * writes; writes nobody batched (the night's own work, a single click elsewhere) are one row per kind and brand;
 * Claude's queue work one row per operation and brand; MoxDOP's background AI jobs one row per job type.
 *
 * States: `queue` (sırada), `run` (çalışıyor), `done` (bitti), `err` (hata var). Open rows sit on top, the rest newest
 * first. Read by the Akış screen every few seconds, so each source is one or two grouped queries.
 */
final class FlowFeed
{
    public const int LIMIT = 40;

    /** Sub rows shown when a row is opened. */
    public const int DETAIL = 40;

    private const array PENDING = ['queued', 'running', 'undoing'];

    private const array OK = ['succeeded', 'partial', 'undo_failed'];

    /** @return array{queue: int, run: int, done: int, err: int} */
    public function counts(): array
    {
        $since = OperatorClock::startOfToday();
        $writes = $this->writeCounts($since);
        $tasks = AiTask::query()->where(fn ($q) => $q->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->orWhere('completed_at', '>=', $since))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $jobs = AiLiveOperation::query()->whereNull('parent_id')->where('kind', AiLiveOperation::KIND_JOB)
            ->where(fn ($q) => $q->whereIn('status', [AiLiveOperation::QUEUED, AiLiveOperation::RUNNING])->orWhere('finished_at', '>=', $since))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $batches = $this->batches($since);
        $deskWaiting = $batches->filter(fn (WorkBatch $b): bool => $b->isOpen() && ! self::stale($b))->sum(fn (WorkBatch $b): int => max(0, $b->total - $b->done - $b->failed));

        return [
            'queue' => $writes['queued'] + (int) ($tasks[AiTask::PENDING] ?? 0) + (int) ($jobs[AiLiveOperation::QUEUED] ?? 0) + $deskWaiting,
            'run' => $writes['running'] + (int) ($tasks[AiTask::CLAIMED] ?? 0) + (int) ($jobs[AiLiveOperation::RUNNING] ?? 0)
                + $batches->filter(fn (WorkBatch $b): bool => $b->status === WorkBatch::RUNNING && ! self::stale($b))->count(),
            'done' => $writes['done'] + (int) ($tasks[AiTask::DONE] ?? 0) + (int) ($tasks[AiTask::CONSUMED] ?? 0) + (int) ($jobs[AiLiveOperation::DONE] ?? 0),
            'err' => $writes['failed'] + (int) ($tasks[AiTask::FAILED] ?? 0) + (int) ($jobs[AiLiveOperation::FAILED] ?? 0)
                + $batches->where('created_at', '>=', $since)->sum('failed'),
        ];
    }

    /**
     * @return Collection<int, array{key: string, kind: string, title: string, who: string, total: int, ok: int, err: int, state: string, at: Carbon, time: string, open: bool, retry: int, note: string|null}>
     */
    public function rows(?string $state = null): Collection
    {
        $since = OperatorClock::startOfToday();
        $brands = Brand::query()->pluck('name', 'id');
        $rows = collect([...$this->batchRows($since, $brands), ...$this->writeRows($since, $brands), ...$this->taskRows($since, $brands), ...$this->jobRows($since)]);

        return $rows->when($state !== null, fn (Collection $c): Collection => $c->where('state', $state))
            ->sortByDesc(fn (array $r): int => (in_array($r['state'], ['run', 'queue'], true) ? 1 : 0) * 10_000_000_000 + $r['at']->getTimestamp())
            ->take(self::LIMIT)->values()
            ->map(fn (array $r): array => $r + ['time' => $r['at']->copy()->timezone(OperatorClock::timezone())->format('H:i')]);
    }

    /**
     * The sub rows of one row, failures first: what went where, its stage (İş → Doğrulama → Sonuç) and why it did not.
     *
     * @return array{items: list<array{id: string, brand: string, subject: string, line: string, state: string, stage: string, retry: bool}>, more: int}
     */
    public function detail(string $key): array
    {
        $brands = Brand::query()->pluck('name', 'id');
        [$type, $rest] = array_pad(explode(':', $key, 2), 2, '');

        return match ($type) {
            'batch' => $this->batchDetail((int) $rest, $brands),
            'writes' => $this->writesDetail($rest, $brands),
            'ai' => $this->taskDetail($rest, $brands),
            default => ['items' => [], 'more' => 0],
        };
    }

    /** A running batch that has not moved for STALE_MINUTES: its job died without a word. */
    public static function stale(WorkBatch $batch): bool
    {
        return $batch->isOpen() && $batch->updated_at !== null && $batch->updated_at->lt(now()->subMinutes(WorkBatches::STALE_MINUTES));
    }

    /**
     * Tek iş yapısı (Aşama 4): the work of the four channels in the same five stages, cached a few minutes. Tespit =
     * open findings, Çözüm = prepared and waiting for a decision, İş = written in the last two weeks (or on the way),
     * Doğrulama = confirmed on the site or profile in two weeks, Sonuç = measured 30 / 90 days later (four months).
     *
     * @return array<string, array<string, int>> channel => stage => count
     */
    public function stages(): array
    {
        return Cache::remember('flow-feed.stages', 300, function (): array {
            $out = array_fill_keys(array_keys(WorkStage::CHANNELS), array_fill_keys(array_keys(WorkStage::LABELS), 0));
            $add = function (string $channel, string $stage, int $n) use (&$out): void {
                if (isset($out[$channel])) {
                    $out[$channel][$stage] += $n;
                }
            };
            $open = Suggestion::query()->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
                ->selectRaw('channel, action_type, count(*) as n')->groupBy('channel', 'action_type')->get();
            foreach ($open as $row) {
                $add(WorkBatches::channelOf((string) $row->channel, (string) $row->action_type), WorkStage::FOUND, (int) $row->getAttribute('n'));
            }
            foreach ((array) (app(RepairDesk::class)->summary()['channels'] ?? []) as $channel => $n) {
                $add((string) $channel, WorkStage::SOLUTION, (int) $n);
            }
            $writes = ExternalWriteAction::query()->where('created_at', '>=', now()->subDays(RepairDesk::TRACK_DAYS))
                ->whereIn('status', [...self::PENDING, ...self::OK])->selectRaw('channel, count(*) as n')->groupBy('channel')->pluck('n', 'channel');
            foreach ($writes as $channel => $n) {
                $add(WorkStage::channelOfWrite((string) $channel), WorkStage::WORK, (int) $n);
            }
            $verified = Suggestion::query()->whereIn('verification', [Suggestion::VERIFY_CONFIRMED, Suggestion::VERIFY_AUTO])
                ->where('verified_at', '>=', now()->subDays(RepairDesk::TRACK_DAYS))
                ->selectRaw('channel, action_type, count(*) as n')->groupBy('channel', 'action_type')->get();
            foreach ($verified as $row) {
                $add(WorkBatches::channelOf((string) $row->channel, (string) $row->action_type), WorkStage::VERIFY, (int) $row->getAttribute('n'));
            }
            $measured = Suggestion::query()->whereNotNull('measured_at')->where('measured_at', '>=', now()->subDays(120))
                ->selectRaw('channel, action_type, count(*) as n')->groupBy('channel', 'action_type')->get();
            foreach ($measured as $row) {
                $add(WorkBatches::channelOf((string) $row->channel, (string) $row->action_type), WorkStage::RESULT, (int) $row->getAttribute('n'));
            }

            return $out;
        });
    }

    /** @return array{queued: int, running: int, done: int, failed: int} */
    private function writeCounts(Carbon|\DateTimeInterface $since): array
    {
        $pending = ExternalWriteAction::query()->whereIn('status', self::PENDING)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $done = ExternalWriteAction::query()->whereIn('status', self::OK)->where('finished_at', '>=', $since)->count();
        $failed = ExternalWriteAction::query()->where('status', 'failed')->where('finished_at', '>=', $since)
            ->when(SchemaState::hasColumn('external_write_actions', 'retried_at'), fn ($q) => $q->whereNull('retried_at'))->count();

        return ['queued' => (int) ($pending['queued'] ?? 0), 'running' => (int) ($pending['running'] ?? 0) + (int) ($pending['undoing'] ?? 0), 'done' => $done, 'failed' => $failed];
    }

    /** @return Collection<int, WorkBatch> */
    private function batches(Carbon|\DateTimeInterface $since): Collection
    {
        if (! SchemaState::hasTable('work_batches')) {
            return collect();
        }

        return WorkBatch::query()->with('user:id,name')->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhereIn('status', [WorkBatch::QUEUED, WorkBatch::RUNNING]))
            ->orderByDesc('id')->limit(self::LIMIT)->get();
    }

    /**
     * @param  Collection<int, string>  $brands
     * @return list<array<string, mixed>>
     */
    private function batchRows(Carbon|\DateTimeInterface $since, Collection $brands): array
    {
        $batches = $this->batches($since);
        if ($batches->isEmpty()) {
            return [];
        }
        $stats = ExternalWriteAction::query()->whereIn('work_batch_id', $batches->modelKeys())
            ->selectRaw('work_batch_id, status, count(*) as n, sum(case when retried_at is null then 0 else 1 end) as retried')
            ->groupBy('work_batch_id', 'status')->get()->groupBy('work_batch_id');

        return $batches->map(function (WorkBatch $batch) use ($stats, $brands): array {
            $writes = $stats->get($batch->id, collect());
            $count = fn (array $statuses): int => (int) $writes->whereIn('status', $statuses)->sum('n');
            $pending = $count(self::PENDING);
            $failedWrites = max(0, $count(['failed']) - (int) $writes->where('status', 'failed')->sum('retried'));
            $stale = self::stale($batch) && $pending === 0;
            $err = $batch->failed + $failedWrites;
            $state = match (true) {
                $stale => 'err',
                $batch->status === WorkBatch::QUEUED && $pending === 0 => 'queue',
                $batch->isOpen() || $pending > 0 => 'run',
                $err > 0 => 'err',
                default => 'done',
            };
            $who = collect([
                $batch->user?->name !== null ? $batch->user->name.' onayladı' : null,
                $batch->brand_id !== null ? ($brands[$batch->brand_id] ?? null) : ($batch->brand_count > 1 ? $batch->brand_count.' marka' : null),
                $pending > 0 && ! $batch->isOpen() ? $pending.' yazma sırada' : null,
            ])->filter()->implode(' · ');

            return [
                'key' => 'batch:'.$batch->id,
                'kind' => $batch->kind === WorkBatch::KIND_CONTENT ? 'icerik' : WorkStage::kindOfChannel((string) $batch->channel),
                'title' => $batch->title,
                'who' => $who,
                'total' => max($batch->total, $batch->done + $batch->failed),
                'ok' => max(0, $batch->done - $pending - $failedWrites),
                'err' => $err,
                'state' => $state,
                'at' => $state === 'run' || $state === 'queue' ? now() : ($batch->finished_at ?? $batch->updated_at ?? now()),
                'open' => true,
                'retry' => $err,
                'note' => $stale ? 'Yarıda kaldı; kalan işler masaya döndü.' : null,
            ];
        })->all();
    }

    /**
     * Writes no batch holds, one row per kind and brand: today's and the ones still on the way.
     *
     * @param  Collection<int, string>  $brands
     * @return list<array<string, mixed>>
     */
    private function writeRows(Carbon|\DateTimeInterface $since, Collection $brands): array
    {
        $hasBatch = SchemaState::hasColumn('external_write_actions', 'work_batch_id');
        $groups = ExternalWriteAction::query()
            ->when($hasBatch, fn ($q) => $q->whereNull('work_batch_id'))
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('finished_at', '>=', $since)->orWhereIn('status', self::PENDING))
            ->selectRaw('action, channel, brand_id, status, count(*) as n, max(updated_at) as last_at'
                .($hasBatch ? ', sum(case when retried_at is null then 0 else 1 end) as retried' : ', 0 as retried'))
            ->groupBy('action', 'channel', 'brand_id', 'status')->get()
            ->groupBy(fn ($r): string => $r->action.'|'.(int) $r->brand_id);

        return $groups->map(function (Collection $group) use ($brands): array {
            $first = $group->first();
            $n = fn (array $statuses): int => (int) $group->whereIn('status', $statuses)->sum('n');
            $pending = $n(self::PENDING);
            $running = $n(['running', 'undoing']);
            $failed = max(0, $n(['failed']) - (int) $group->where('status', 'failed')->sum('retried'));
            $ok = $n(self::OK);
            $total = (int) $group->sum('n');
            $state = match (true) {
                $running > 0 => 'run',
                $pending > 0 => $ok + $failed > 0 ? 'run' : 'queue',
                $failed > 0 => 'err',
                default => 'done',
            };
            $label = RepairDesk::WRITE_LABELS[$first->action] ?? Str::headline((string) $first->action);

            return [
                'key' => 'writes:'.$first->action.'|'.(int) $first->brand_id,
                'kind' => WorkStage::kindOfChannel(WorkStage::channelOfWrite((string) $first->channel)),
                'title' => $label.($total > 1 ? ': '.number_format($total, 0, ',', '.').' iş' : ''),
                'who' => trim(($brands[$first->brand_id] ?? 'Marka dışı').' · MoxDOP'),
                'total' => $total,
                'ok' => $ok,
                'err' => $failed,
                'state' => $state,
                'at' => in_array($state, ['run', 'queue'], true) ? now() : Carbon::parse((string) $group->max('last_at')),
                'open' => true,
                'retry' => $failed,
                'note' => null,
            ];
        })->values()->all();
    }

    /**
     * Claude's queue (the Claude subscription): one row per operation and brand — what is waiting, being written,
     * written today or could not be done.
     *
     * @param  Collection<int, string>  $brands
     * @return list<array<string, mixed>>
     */
    private function taskRows(Carbon|\DateTimeInterface $since, Collection $brands): array
    {
        $rows = AiTask::query()->where(fn ($q) => $q->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->orWhere('completed_at', '>=', $since))
            ->selectRaw('operation, brand_id, status, count(*) as n, max(coalesce(completed_at, claimed_at, created_at)) as last_at, max(subject) as subject')
            ->groupBy('operation', 'brand_id', 'status')->get()->groupBy(fn ($r): string => $r->operation.'|'.(int) $r->brand_id);

        return $rows->map(function (Collection $group) use ($brands): array {
            $first = $group->first();
            $n = fn (array $statuses): int => (int) $group->whereIn('status', $statuses)->sum('n');
            $running = $n([AiTask::CLAIMED]);
            $waiting = $n([AiTask::PENDING]);
            $failed = $n([AiTask::FAILED]);
            $ok = $n([AiTask::DONE, AiTask::CONSUMED]);
            $total = $running + $waiting + $failed + $ok;
            $state = match (true) {
                $running > 0 => 'run',
                $waiting > 0 => $ok + $failed > 0 ? 'run' : 'queue',
                $failed > 0 => 'err',
                default => 'done',
            };
            $label = AiOperationLabels::for((string) $first->operation);
            $subject = $total === 1 ? (string) $group->firstWhere('subject', '!=', null)?->subject : '';

            return [
                'key' => 'ai:'.$first->operation.'|'.(int) $first->brand_id,
                'kind' => WorkStage::kindOfOperation((string) $first->operation),
                'title' => $label.($subject !== '' ? ': '.Str::limit($subject, 80) : ''),
                'who' => trim(($first->brand_id !== null ? ($brands[$first->brand_id] ?? 'Marka').' · ' : '').'Claude'),
                'total' => $total,
                'ok' => $ok,
                'err' => $failed,
                'state' => $state,
                'at' => in_array($state, ['run', 'queue'], true) ? now() : Carbon::parse((string) $group->max('last_at')),
                'open' => $total > 0,
                'retry' => 0,
                'note' => null,
            ];
        })->values()->all();
    }

    /**
     * MoxDOP's own background AI jobs (AI işleri): one row per job type of today.
     *
     * @return list<array<string, mixed>>
     */
    private function jobRows(Carbon|\DateTimeInterface $since): array
    {
        $rows = AiLiveOperation::query()->whereNull('parent_id')->where('kind', AiLiveOperation::KIND_JOB)
            ->where(fn ($q) => $q->whereIn('status', [AiLiveOperation::QUEUED, AiLiveOperation::RUNNING])->orWhere('finished_at', '>=', $since))
            ->selectRaw('label, status, count(*) as n, max(coalesce(finished_at, started_at, queued_at)) as last_at')
            ->groupBy('label', 'status')->get()->groupBy('label');

        return $rows->map(function (Collection $group, string $label): array {
            $n = fn (array $statuses): int => (int) $group->whereIn('status', $statuses)->sum('n');
            $running = $n([AiLiveOperation::RUNNING]);
            $waiting = $n([AiLiveOperation::QUEUED]);
            $failed = $n([AiLiveOperation::FAILED]);
            $ok = $n([AiLiveOperation::DONE]);
            $state = match (true) {
                $running > 0 => 'run',
                $waiting > 0 => 'queue',
                $failed > 0 => 'err',
                default => 'done',
            };

            return [
                'key' => 'job:'.$label,
                'kind' => 'ai',
                'title' => $label !== '' ? $label : 'AI işi',
                'who' => 'MoxDOP · arka plan',
                'total' => (int) $group->sum('n'),
                'ok' => $ok,
                'err' => $failed,
                'state' => $state,
                'at' => in_array($state, ['run', 'queue'], true) ? now() : Carbon::parse((string) $group->max('last_at')),
                'open' => false,
                'retry' => 0,
                'note' => null,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, string>  $brands
     * @return array{items: list<array<string, mixed>>, more: int}
     */
    private function batchDetail(int $id, Collection $brands): array
    {
        $batch = SchemaState::hasTable('work_batches') ? WorkBatch::query()->find($id) : null;
        if ($batch === null) {
            return ['items' => [], 'more' => 0];
        }
        $items = collect((array) $batch->failures)->map(fn (array $f, int $i): array => [
            'id' => 'f'.$i.'-'.($f['id'] ?? ''),
            'brand' => (string) ($f['brand'] ?? ''),
            'subject' => (string) ($f['title'] ?? ''),
            'line' => RepairDesk::plainError((string) ($f['reason'] ?? '')),
            'state' => 'err',
            'stage' => WorkStage::LABELS[WorkStage::SOLUTION],
            'retry' => isset($f['id']),
        ]);
        $writes = ExternalWriteAction::query()->where('work_batch_id', $batch->id);

        return $this->withWrites($items, $writes, $brands);
    }

    /**
     * @param  Collection<int, string>  $brands
     * @return array{items: list<array<string, mixed>>, more: int}
     */
    private function writesDetail(string $rest, Collection $brands): array
    {
        [$action, $brand] = array_pad(explode('|', $rest, 2), 2, '0');
        $since = OperatorClock::startOfToday();
        $writes = ExternalWriteAction::query()->where('action', $action)
            ->when((int) $brand > 0, fn ($q) => $q->where('brand_id', (int) $brand), fn ($q) => $q->whereNull('brand_id'))
            ->when(SchemaState::hasColumn('external_write_actions', 'work_batch_id'), fn ($q) => $q->whereNull('work_batch_id'))
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('finished_at', '>=', $since)->orWhereIn('status', self::PENDING));

        return $this->withWrites(collect(), $writes, $brands);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  Builder<ExternalWriteAction>  $writes
     * @param  Collection<int, string>  $brands
     * @return array{items: list<array<string, mixed>>, more: int}
     */
    private function withWrites(Collection $items, Builder $writes, Collection $brands): array
    {
        $total = (clone $writes)->count();
        // Failures first (the ones not sent again yet), then the newest.
        $list = (clone $writes)->with('digitalAsset:id,name')
            ->orderByRaw("case when status = 'failed' and retried_at is null then 0 when status in ('queued','running','undoing') then 1 else 2 end")
            ->orderByDesc('id')->limit(self::DETAIL)->get();
        $suggestions = Suggestion::query()->with('page:id,url')->whereIn('id', $list->pluck('suggestion_id')->filter()->unique())->get(['id', 'title', 'page_id', 'verification', 'outcome', 'measured_at'])->keyBy('id');
        foreach ($list as $write) {
            $suggestion = $suggestions[$write->suggestion_id] ?? null;
            $failed = $write->status === 'failed' && $write->retried_at === null;
            $lines = RepairDesk::writeLines($write);
            $items->push([
                'id' => 'w'.$write->id,
                'brand' => (string) ($brands[$write->brand_id] ?? $write->digitalAsset?->name ?? ''),
                'subject' => self::writeSubject($write, $suggestion),
                'line' => $failed ? RepairDesk::plainError((string) $write->error) : ($lines[0]['text'] ?? (RepairDesk::WRITE_LABELS[$write->action] ?? $write->statusLabel())),
                'state' => match (true) {
                    $failed => 'err',
                    in_array($write->status, self::PENDING, true) => $write->status === 'queued' ? 'queue' : 'run',
                    default => 'done',
                },
                'stage' => WorkStage::LABELS[WorkStage::ofWrite($write, $suggestion)],
                'retry' => $failed,
            ]);
        }

        return ['items' => $items->values()->all(), 'more' => max(0, $total - $list->count())];
    }

    /**
     * @param  Collection<int, string>  $brands
     * @return array{items: list<array<string, mixed>>, more: int}
     */
    private function taskDetail(string $rest, Collection $brands): array
    {
        [$operation, $brand] = array_pad(explode('|', $rest, 2), 2, '0');
        $since = OperatorClock::startOfToday();
        $query = AiTask::query()->where('operation', $operation)
            ->when((int) $brand > 0, fn ($q) => $q->where('brand_id', (int) $brand), fn ($q) => $q->whereNull('brand_id'))
            ->where(fn ($q) => $q->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->orWhere('completed_at', '>=', $since));
        $total = (clone $query)->count();
        $tasks = $query->orderByRaw("case status when 'failed' then 0 when 'claimed' then 1 when 'pending' then 2 else 3 end")->orderByDesc('id')
            ->limit(self::DETAIL)->get(['id', 'brand_id', 'subject', 'status', 'output', 'error']);

        return ['items' => $tasks->map(fn (AiTask $task): array => [
            'id' => 't'.$task->id,
            'brand' => (string) ($brands[$task->brand_id] ?? ''),
            'subject' => (string) ($task->subject ?? AiOperationLabels::for($operation)),
            'line' => match ($task->status) {
                AiTask::FAILED => Str::limit(RepairDesk::plainError((string) $task->error), 140),
                AiTask::PENDING => 'Claude\'un sırasında',
                AiTask::CLAIMED => 'Claude yazıyor',
                default => (string) (LiveFeed::preview($task->output) ?? 'Bitti'),
            },
            'state' => match ($task->status) {
                AiTask::FAILED => 'err',
                AiTask::PENDING => 'queue',
                AiTask::CLAIMED => 'run',
                default => 'done',
            },
            'stage' => WorkStage::LABELS[WorkStage::SOLUTION],
            'retry' => false,
        ])->values()->all(), 'more' => max(0, $total - $tasks->count())];
    }

    /** What a write was about: the desk row's page or title, else what the payload names. */
    public static function writeSubject(ExternalWriteAction $write, ?Suggestion $suggestion = null): string
    {
        $url = (string) ($suggestion?->page?->url ?? data_get($write->request_payload, 'changes.0.url') ?? data_get($write->request_payload, 'url') ?? '');
        $path = $url !== '' ? (string) (parse_url($url, PHP_URL_PATH) ?: $url) : '';
        $title = (string) ($suggestion?->title ?? data_get($write->request_payload, 'title') ?? data_get($write->request_payload, 'post.summary') ?? '');

        return Str::limit(trim($path !== '' ? $path : ($title !== '' ? $title : (RepairDesk::WRITE_LABELS[$write->action] ?? $write->action))), 90);
    }
}
