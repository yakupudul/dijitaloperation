<?php

namespace App\Services\Intel;

use App\Services\Demand\DataForSeoIntegrationLookup;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * DataForSEO standard queue (Faz 8): tasks are posted in batches (cheaper than live), each posted task is a row in
 * `dataforseo_tasks` with its cost, and `collect()` (every few minutes) reads finished results and hands them to
 * the purpose's handler. Every paid call of the intel features is recorded here, so the monthly cap and the
 * Maliyetler screen read one table.
 */
final class DataForSeoTaskQueue
{
    private const array WAITING_CODES = [40601, 40602];

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
    ) {}

    public function available(): bool
    {
        return $this->integrations->active() !== null;
    }

    /**
     * @param  list<array{payload: array<string, mixed>, subject_type?: ?string, subject_id?: ?int}>  $items
     * @return list<int> task row ids (failed posts included, with status failed)
     */
    public function post(string $postEndpoint, string $getPrefix, string $purpose, ?int $brandId, array $items): array
    {
        $integration = $this->integrations->active() ?? throw new RuntimeException('DataForSEO bağlantısı yok.');
        $ids = [];
        foreach (array_chunk($items, 100) as $chunk) {
            try {
                $response = $this->client->request($integration, 'POST', $postEndpoint, DataForSeoApiClient::CHARGE_CLASS_PAID_CREATE, array_map(static fn (array $i): array => $i['payload'], $chunk));
                $tasks = $response->tasks;
            } catch (Throwable $exception) {
                report($exception);
                $tasks = [];
                $error = $exception->getMessage();
            }
            foreach ($chunk as $index => $item) {
                $task = is_array($tasks[$index] ?? null) ? $tasks[$index] : null;
                $ok = $task !== null && in_array((int) ($task['status_code'] ?? 0), [20000, 20100], true) && filled($task['id'] ?? null);
                $ids[] = (int) DB::table('dataforseo_tasks')->insertGetId([
                    'brand_id' => $brandId, 'purpose' => $purpose,
                    'subject_type' => $item['subject_type'] ?? null, 'subject_id' => $item['subject_id'] ?? null,
                    'get_endpoint' => $getPrefix, 'task_id' => $ok ? (string) $task['id'] : null,
                    'payload' => json_encode($item['payload'], JSON_UNESCAPED_UNICODE),
                    'status' => $ok ? 'posted' : 'failed', 'cost_usd' => round((float) ($task['cost'] ?? 0), 5),
                    'error' => $ok ? null : mb_substr((string) ($task['status_message'] ?? $error ?? 'Görev oluşturulamadı.'), 0, 1000),
                    'posted_at' => now(), 'completed_at' => $ok ? null : now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $ok) {
                    $this->handler($purpose)?->handleFailure(DB::table('dataforseo_tasks')->find(end($ids)), (string) ($task['status_message'] ?? $error ?? 'Görev oluşturulamadı.'));
                }
            }
            unset($error);
        }

        return $ids;
    }

    /** Record a paid live call made outside the queue (backlinks), so it counts toward the cap. */
    public function recordLive(string $purpose, ?int $brandId, string $endpoint, float $cost, ?string $subjectType = null, ?int $subjectId = null, ?string $error = null): void
    {
        DB::table('dataforseo_tasks')->insert([
            'brand_id' => $brandId, 'purpose' => $purpose, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'get_endpoint' => $endpoint, 'status' => $error === null ? 'completed' : 'failed', 'cost_usd' => round($cost, 5),
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'posted_at' => now(), 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{completed: int, failed: int, waiting: int} */
    public function collect(?int $limit = null): array
    {
        $stats = ['completed' => 0, 'failed' => 0, 'waiting' => 0];
        $integration = $this->integrations->active();
        if ($integration === null) {
            return $stats;
        }
        $cfg = (array) config('moxdop-intel.tasks', []);
        $rows = DB::table('dataforseo_tasks')->where('status', 'posted')->whereNotNull('task_id')
            ->where('posted_at', '<=', now()->subSeconds((int) ($cfg['poll_after_seconds'] ?? 60)))
            ->orderBy('posted_at')->limit($limit ?? (int) ($cfg['per_run'] ?? 200))->get();
        foreach ($rows as $row) {
            try {
                $response = $this->client->request($integration, 'GET', $row->get_endpoint.'/'.$row->task_id, DataForSeoApiClient::CHARGE_CLASS_SAFE_READ);
                $task = (array) ($response->tasks[0] ?? []);
                $code = (int) ($task['status_code'] ?? 0);
                if ($code === 20000) {
                    $this->handler($row->purpose)?->handleResult($row, (array) ($task['result'][0] ?? []));
                    DB::table('dataforseo_tasks')->where('id', $row->id)->update(['status' => 'completed', 'completed_at' => now(), 'polls' => $row->polls + 1, 'updated_at' => now()]);
                    $stats['completed']++;

                    continue;
                }
                if (in_array($code, self::WAITING_CODES, true) && ! $this->expired($row, $cfg)) {
                    DB::table('dataforseo_tasks')->where('id', $row->id)->update(['polls' => $row->polls + 1, 'updated_at' => now()]);
                    $stats['waiting']++;

                    continue;
                }
                $this->fail($row, (string) ($task['status_message'] ?? 'Sonuç alınamadı ('.$code.').'));
                $stats['failed']++;
            } catch (Throwable $exception) {
                report($exception);
                if ($this->expired($row, $cfg)) {
                    $this->fail($row, $exception->getMessage());
                    $stats['failed']++;
                } else {
                    DB::table('dataforseo_tasks')->where('id', $row->id)->update(['polls' => $row->polls + 1, 'updated_at' => now()]);
                    $stats['waiting']++;
                }
            }
        }

        return $stats;
    }

    public function spentThisMonth(int $brandId): float
    {
        return (float) DB::table('dataforseo_tasks')->where('brand_id', $brandId)->where('posted_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    private function expired(object $row, array $cfg): bool
    {
        return (int) $row->polls + 1 >= (int) ($cfg['max_polls'] ?? 60)
            || now()->diffInHours($row->posted_at, true) >= (int) ($cfg['give_up_hours'] ?? 24);
    }

    private function fail(object $row, string $error): void
    {
        DB::table('dataforseo_tasks')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($error, 0, 1000), 'completed_at' => now(), 'polls' => $row->polls + 1, 'updated_at' => now()]);
        $this->handler($row->purpose)?->handleFailure($row, $error);
    }

    private function handler(string $purpose): ?DataForSeoTaskHandler
    {
        $class = config('moxdop-intel.tasks.handlers.'.$purpose);

        return is_string($class) && class_exists($class) ? app($class) : null;
    }
}
