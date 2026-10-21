<?php

namespace App\Services\Operations;

use App\Models\ClaudeNote;
use App\Support\SchemaState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * A silent redis queue (stale "queue:<name>" heartbeat; "default" was silent from 11:35 to after the 18:11 deploy on
 * 2026-10-13, so jobs queued after a deploy never ran): look inside and get it moving again without the server shell.
 * Run by OpsWatchdog from its own cron line.
 *
 * - A job reserved far longer than the supervisor's timeout means a worker hung where the timeout alarm can't reach it
 *   (a blocking call); it also keeps `horizon:terminate` on deploy from finishing. That queue's `horizon:work`
 *   processes are killed; Horizon starts fresh ones. At most once per half hour per queue.
 * - Nothing reserved but jobs waiting: no worker is taking them, so Horizon is asked to restart (`horizon:terminate`,
 *   supervisor autorestart), at most once an hour.
 * - Otherwise (a long backlog ahead of the probe) nothing is touched.
 *
 * What it saw and did is kept as a system Claude note (read over MCP), at most once an hour per queue.
 */
class QueueDoctor
{
    /** Seconds a reserved job may run beyond the supervisor timeout before its worker counts as hung. */
    private const int HUNG_GRACE_SECONDS = 300;

    /**
     * @param  list<string>  $queues  stale queue names
     * @return list<string> one line per queue: what was seen and done
     */
    public function treat(array $queues): array
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'redis') {
            return [];
        }
        $lines = [];
        foreach ($queues as $queue) {
            try {
                $lines[] = $this->treatQueue($queue);
            } catch (Throwable $exception) {
                $lines[] = $queue.': incelenemedi ('.mb_substr($exception->getMessage(), 0, 160).').';
            }
        }

        return $lines;
    }

    private function treatQueue(string $queue): string
    {
        $snapshot = $this->snapshot($queue);
        $limit = $this->timeout($queue) + self::HUNG_GRACE_SECONDS;
        $hung = array_values(array_filter($snapshot['reserved'], fn (array $job): bool => $job['seconds'] > $limit));

        $action = 'dokunulmadı';
        if ($hung !== []) {
            if (Cache::add('ops:queue-doctor:kill:'.$queue, true, 1800)) {
                $killed = $this->killWorkers($queue);
                $action = $killed > 0 ? $killed.' takılı işçi durduruldu, Horizon yenisini başlatıyor' : 'takılı işçi bulunamadı';
            } else {
                $action = 'takılı işçi yarım saat içinde zaten durduruldu';
            }
        } elseif ($snapshot['reserved'] === [] && $snapshot['pending'] > 0) {
            if (Cache::add('ops:queue-doctor:restart', true, 3600)) {
                $this->restartHorizon();
                $action = 'işleyen işçi yok, Horizon yeniden başlatıldı';
            } else {
                $action = 'Horizon son bir saatte zaten yeniden başlatıldı';
            }
        }

        $line = $queue.': '.$snapshot['pending'].' iş bekliyor'
            .($snapshot['oldest_minutes'] !== null ? ' (en eskisi '.$snapshot['oldest_minutes'].' dk)' : '')
            .($snapshot['pending_by_job'] !== [] ? ' · bekleyenler: '.$this->histogram($snapshot['pending_by_job']) : '')
            .' · çalışan: '.($snapshot['reserved'] === [] ? 'yok' : implode(', ', array_map(fn (array $job): string => $job['name'].' '.intdiv($job['seconds'], 60).' dk', $snapshot['reserved'])))
            .' · '.$action.'.';
        Log::warning('ops.queue-doctor', ['queue' => $queue, 'line' => $line]);

        if (Cache::add('ops:queue-doctor:note:'.$queue, true, 3600)) {
            $failed = $this->failedJobs($queue);
            ClaudeNote::query()->create(['brand_id' => null, 'kind' => 'observation', 'status' => ClaudeNote::OPEN,
                'text' => 'Kuyruk "'.$queue.'" sessiz ('.now()->format('Y-m-d H:i').'): '.$line.($failed !== '' ? "\nSon 6 saatte başarısız: ".$failed : '')]);
        }

        return $line;
    }

    /**
     * @return array{pending: int, oldest_minutes: ?int, pending_by_job: array<string, int>, reserved: list<array{name: string, seconds: int}>}
     */
    protected function snapshot(string $queue): array
    {
        $redis = Queue::connection('redis')->getConnection();
        $key = 'queues:'.$queue;
        $pending = (int) $redis->llen($key);
        $byJob = [];
        $oldest = null;
        foreach ((array) $redis->lrange($key, 0, 999) as $raw) {
            $payload = json_decode((string) $raw, true);
            $name = (string) data_get($payload, 'displayName', '?');
            $byJob[$name] = ($byJob[$name] ?? 0) + 1;
            $pushed = (float) (data_get($payload, 'pushedAt') ?? data_get($payload, 'createdAt') ?? 0);
            if ($pushed > 0) {
                $oldest = $oldest === null ? $pushed : min($oldest, $pushed);
            }
        }
        $retryAfter = (int) config('queue.connections.redis.retry_after', 1800);
        $reserved = [];
        foreach ((array) $redis->zrangebyscore($key.':reserved', '-inf', '+inf', ['withscores' => true]) as $raw => $expiresAt) {
            $reserved[] = ['name' => (string) data_get(json_decode((string) $raw, true), 'displayName', '?'),
                'seconds' => max(0, now()->getTimestamp() - ((int) $expiresAt - $retryAfter))];
        }

        return ['pending' => $pending, 'oldest_minutes' => $oldest !== null ? (int) floor((microtime(true) - $oldest) / 60) : null,
            'pending_by_job' => $byJob, 'reserved' => $reserved];
    }

    /** Kills this queue's `horizon:work` processes (same host, same user); returns how many. */
    protected function killWorkers(string $queue): int
    {
        if (! function_exists('posix_kill')) {
            return 0;
        }
        $killed = 0;
        foreach ((array) glob('/proc/[0-9]*/cmdline') as $file) {
            $args = explode("\0", (string) @file_get_contents((string) $file));
            if (! in_array('horizon:work', $args, true)) {
                continue;
            }
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--queue=') && in_array($queue, explode(',', trim(substr($arg, 8), '"\'')), true)) {
                    $killed += posix_kill((int) basename(dirname((string) $file)), 9) ? 1 : 0;
                    break;
                }
            }
        }

        return $killed;
    }

    protected function restartHorizon(): void
    {
        Artisan::call('horizon:terminate');
    }

    private function timeout(string $queue): int
    {
        foreach ((array) config('horizon.defaults', []) as $name => $supervisor) {
            if (in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                return (int) (config('horizon.environments.'.app()->environment().'.'.$name.'.timeout') ?? $supervisor['timeout'] ?? 900);
            }
        }

        return 900;
    }

    /** @param  array<string, int>  $counts */
    private function histogram(array $counts): string
    {
        arsort($counts);

        return implode(', ', array_map(fn (string $name, int $count): string => class_basename($name).' ×'.$count, array_keys(array_slice($counts, 0, 5, true)), array_slice($counts, 0, 5, true)));
    }

    private function failedJobs(string $queue): string
    {
        if (! SchemaState::hasTable('failed_jobs')) {
            return '';
        }
        $groups = [];
        foreach (DB::table('failed_jobs')->where('queue', $queue)->where('failed_at', '>=', now()->subHours(6))->latest('id')->limit(300)->get(['payload', 'exception']) as $row) {
            $name = class_basename((string) data_get(json_decode((string) $row->payload, true), 'displayName', '?'));
            $groups[$name] ??= ['count' => 0, 'why' => mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 140)];
            $groups[$name]['count']++;
        }
        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return implode('; ', array_map(fn (string $name, array $group): string => $name.' ×'.$group['count'].' ('.$group['why'].')', array_keys(array_slice($groups, 0, 5, true)), array_slice($groups, 0, 5, true)));
    }
}
