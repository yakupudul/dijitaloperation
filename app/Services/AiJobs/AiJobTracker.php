<?php

namespace App\Services\AiJobs;

use App\Models\AiLiveOperation;
use App\Models\User;
use App\Services\Ai\AiLiveOperations;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI işleri — queued AI jobs: a tracked job (AiJobCatalog) gets a "Sırada" row when queued, turns "Çalışıyor" when a
 * worker picks it up and "Bitti" / "Hata" / "Durduruldu" when it ends; the agent calls it makes point at that row. Any
 * queued job being processed is remembered (uuid, class) so its calls can be stopped too. Removing a queued job deletes
 * it from a database queue, else the worker drops it when it picks it up; stopping a running one sets a flag the job
 * checks between AI calls (AiCancellation). Never throws from a queue event.
 */
final class AiJobTracker
{
    /** Job rows running longer than this are closed as failed (the worker died). */
    public const int STALE_JOB_MINUTES = 180;

    /** Queued rows older than this are closed as failed (the queue lost them). */
    public const int STALE_QUEUED_HOURS = 24;

    /** @var list<array{uuid: string, class: string, row: ?int}> jobs this process is running (innermost last) */
    private array $stack = [];

    private ?bool $ready = null;

    public function queued(JobQueued $event): void
    {
        try {
            if (! $this->ready() || ! AiJobCatalog::tracks($event->job)) {
                return;
            }
            $payload = $event->payload();
            $uuid = (string) ($payload['uuid'] ?? '');
            if ($uuid === '') {
                return;
            }
            $this->openRow($event->job, $uuid, AiLiveOperation::QUEUED, [
                'queue_connection' => mb_substr((string) $event->connectionName, 0, 64),
                'queue_name' => $event->queue !== null ? mb_substr((string) $event->queue, 0, 120) : null,
                'queue_job_id' => $event->id !== null ? mb_substr((string) $event->id, 0, 64) : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI job could not be recorded as queued.', ['error' => $exception->getMessage()]);
        }
    }

    public function processing(JobProcessing $event): void
    {
        try {
            if (! $this->ready()) {
                return;
            }
            $uuid = (string) ($event->job->uuid() ?? '');
            $class = (string) $event->job->resolveName();
            $row = $uuid !== '' ? AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_JOB)->where('job_uuid', $uuid)->first() : null;
            if ($row !== null && $row->status === AiLiveOperation::CANCELLED) {
                // Removed while it waited ("Kuyruktan kaldır") on a queue we could not delete from: drop it unrun.
                $event->job->delete();

                return;
            }
            if ($row === null && AiJobCatalog::tracksClass($class)) {
                $command = $this->command($event->job);
                $row = $command !== null ? $this->openRow($command, $uuid, AiLiveOperation::RUNNING, [
                    'queue_connection' => mb_substr((string) $event->connectionName, 0, 64),
                    'queue_name' => mb_substr((string) $event->job->getQueue(), 0, 120),
                ]) : null;
            } elseif ($row !== null) {
                $row->forceFill(['status' => AiLiveOperation::RUNNING, 'started_at' => now(), 'finished_at' => null, 'duration_ms' => null, 'error' => null])->save();
            }
            $this->stack[] = ['uuid' => $uuid, 'class' => $class, 'row' => $row?->id !== null ? (int) $row->id : null];
        } catch (Throwable $exception) {
            Log::warning('AI job could not be recorded as running.', ['error' => $exception->getMessage()]);
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->end($event->job, $event->job->hasFailed() ? 'İş başarısız oldu.' : null, true);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $final = $event->job->hasFailed() || $event->job instanceof SyncJob || $event->connectionName === 'sync';
        $this->end($event->job, $event->exception->getMessage(), $final);
    }

    /**
     * A job failed for good — also when the worker kills it for running past its timeout (no "processed" event comes
     * then): the row is closed at once with the cost of the calls it made, never left "Çalışıyor" until the sweep.
     */
    public function failed(JobFailed $event): void
    {
        $this->end($event->job, self::failureMessage($event->exception), true);
    }

    public static function failureMessage(Throwable $exception): string
    {
        return $exception instanceof TimeoutExceededException ? 'Zaman aşımı: iş süresini aştı, işçi durdurdu.' : $exception->getMessage();
    }

    /** The innermost queued job this process runs: uuid, class and its AI işleri row (tracked jobs only). */
    public function current(): ?array
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /** The row of the innermost tracked job this process runs. */
    public function currentRowId(): ?int
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['row'] !== null) {
                return $this->stack[$i]['row'];
            }
        }

        return null;
    }

    /** Whether the operator asked to stop the tracked job this process runs. */
    public function cancelRequested(): bool
    {
        $row = $this->currentRowId();
        if ($row === null) {
            return false;
        }
        try {
            return AiLiveOperation::query()->whereKey($row)->whereNotNull('cancel_requested_at')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "Durdur": a queued job is removed (deleted from a database queue, else dropped when a worker picks it up) and
     * marked "Durduruldu" at once; a running job (or a call inside a queued job) gets the cooperative stop flag.
     *
     * @return string|null the operator message, null when the row can no longer be stopped
     */
    public function cancel(AiLiveOperation $row, ?User $by): ?string
    {
        if ($row->status === AiLiveOperation::QUEUED) {
            $removed = $this->removeFromQueue($row);
            $row->forceFill([
                'status' => AiLiveOperation::CANCELLED,
                'error' => 'Çalışmadan kuyruktan kaldırıldı.',
                'cancel_requested_at' => now(),
                'cancel_requested_by' => $by?->id,
                'finished_at' => now(),
            ])->save();

            return $removed ? 'İş kuyruktan kaldırıldı.' : 'İş kuyruktan kaldırıldı; işçi aldığında çalıştırmadan atlayacak.';
        }
        if ($row->status !== AiLiveOperation::RUNNING || (! $row->isJob() && $row->job_uuid === null)) {
            return null;
        }
        if ($row->cancel_requested_at === null) {
            $row->forceFill(['cancel_requested_at' => now(), 'cancel_requested_by' => $by?->id])->save();
        }

        return 'Durdurma isteği gönderildi. İş, sürmekte olan AI çağrısı bitince duracak; o çağrının sonucu kullanılmayacak.';
    }

    /** Whether "Durdur" applies to the row (queued job, running job, or running call inside a queued job). */
    public static function stoppable(AiLiveOperation $row): bool
    {
        return $row->status === AiLiveOperation::QUEUED
            || ($row->status === AiLiveOperation::RUNNING && $row->cancel_requested_at === null && ($row->isJob() || $row->job_uuid !== null));
    }

    /** Closes job rows whose worker died long ago and queued rows the queue lost. */
    public static function sweepStale(): void
    {
        try {
            AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_JOB)->where('status', AiLiveOperation::RUNNING)
                ->where('started_at', '<', now()->subMinutes(self::STALE_JOB_MINUTES))
                ->update(['status' => AiLiveOperation::FAILED, 'error' => 'İş yarıda kaldı (zaman aşımı).', 'finished_at' => now()]);
            AiLiveOperation::query()->where('status', AiLiveOperation::QUEUED)
                ->where('queued_at', '<', now()->subHours(self::STALE_QUEUED_HOURS))
                ->update(['status' => AiLiveOperation::FAILED, 'error' => 'Kuyrukta işlenmedi.', 'finished_at' => now()]);
        } catch (Throwable) {
            // Display only.
        }
    }

    private function end(Job $job, ?string $error, bool $final): void
    {
        $uuid = (string) ($job->uuid() ?? '');
        $entry = null;
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['uuid'] === $uuid) {
                $entry = $this->stack[$i];
                array_splice($this->stack, $i, 1);
                break;
            }
        }
        if ($entry === null || $entry['row'] === null) {
            return;
        }
        try {
            $row = AiLiveOperation::query()->find($entry['row']);
            if ($row === null || ! $row->isOpen()) {
                return;
            }
            $calls = AiLiveOperation::query()->where('parent_id', $row->id)
                ->selectRaw('sum(cost_usd) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')->first();
            $status = match (true) {
                $row->cancel_requested_at !== null => AiLiveOperation::CANCELLED,
                $error !== null && $final => AiLiveOperation::FAILED,
                $error !== null => AiLiveOperation::QUEUED,
                default => AiLiveOperation::DONE,
            };
            $row->forceFill([
                'status' => $status,
                'error' => match ($status) {
                    AiLiveOperation::CANCELLED => 'Operatör durdurdu.',
                    AiLiveOperation::QUEUED => Str::limit('Tekrar denenecek: '.self::oneLine((string) $error), 290),
                    AiLiveOperation::FAILED => Str::limit(self::oneLine((string) $error), 290),
                    default => null,
                },
                'cost_usd' => $calls?->cost !== null ? round((float) $calls->cost, 6) : null,
                'input_tokens' => $calls?->input_tokens !== null ? (int) $calls->input_tokens : null,
                'output_tokens' => $calls?->output_tokens !== null ? (int) $calls->output_tokens : null,
                'duration_ms' => $status === AiLiveOperation::QUEUED ? null : max(0, (int) abs($row->started_at?->diffInMilliseconds(now()) ?? 0)),
                'finished_at' => $status === AiLiveOperation::QUEUED ? null : now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('AI job could not be closed.', ['error' => $exception->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function openRow(object $command, string $uuid, string $status, array $attributes): AiLiveOperation
    {
        $description = AiJobCatalog::describe($command);
        $user = auth()->id() ?? Context::getHidden(AiLiveOperations::USER_CONTEXT) ?? $description['user_id'];

        return AiLiveOperation::query()->create([
            'kind' => AiLiveOperation::KIND_JOB,
            'job_uuid' => mb_substr($uuid, 0, 64),
            'job_class' => mb_substr($command::class, 0, 190),
            'operation' => $description['operation'] !== null ? mb_substr($description['operation'], 0, 120) : null,
            'label' => mb_substr($description['label'], 0, 190),
            'agent' => mb_substr(class_basename($command), 0, 190),
            'status' => $status,
            'user_id' => is_numeric($user) ? (int) $user : null,
            'subject' => $description['subject'],
            'context' => $description['context'] !== [] ? $description['context'] : null,
            'link' => $description['link'] !== null ? mb_substr($description['link'], 0, 500) : null,
            'queued_at' => now(),
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    /** The job object of a queue job payload (a tracked job picked up without a queued row, e.g. the sync queue). */
    private function command(Job $job): ?object
    {
        try {
            $serialized = $job->payload()['data']['command'] ?? null;
            if (! is_string($serialized)) {
                return null;
            }
            if (! str_starts_with($serialized, 'O:')) {
                $serialized = Crypt::decryptString($serialized);
            }
            $command = unserialize($serialized);

            return is_object($command) ? $command : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function removeFromQueue(AiLiveOperation $row): bool
    {
        $connection = (string) $row->queue_connection;
        if ($connection === '' || ! ctype_digit((string) $row->queue_job_id) || config("queue.connections.{$connection}.driver") !== 'database') {
            return false;
        }
        try {
            return DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->where('id', (int) $row->queue_job_id)->whereNull('reserved_at')->delete() > 0;
        } catch (Throwable $exception) {
            Log::warning('Queued AI job could not be deleted from the queue.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function ready(): bool
    {
        return $this->ready ??= Schema::hasColumn('ai_live_operations', 'job_uuid');
    }
}
