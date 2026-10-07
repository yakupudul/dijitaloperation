<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Services\Observability\ErrorTriage;
use App\Services\Operations\AutoDeployStatus;
use App\Services\Operations\SystemHealthReader;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Reads how the system is doing, from stored state only: deployed release, the last automatic deploy check (with the failing tests when it stopped), scheduler and stopped workers, queue waits, top application errors of the last 7 days (class, file:line, count), open alerts grouped like the Hata merkezi (you = needs the operator, code = software error, auto = heals by itself), AI tasks you could not do and approved external writes / automatic Business Profile posts that failed in the last 3 days with their reasons. Use it to find bugs to report or fix and improvements to propose; it changes nothing.')]
#[IsReadOnly]
class SystemHealth extends Tool
{
    public function __construct(
        private readonly SystemHealthReader $health,
        private readonly ErrorTriage $triage,
    ) {}

    public function handle(Request $request): Response
    {
        $out = [];
        try {
            $read = $this->health->read();
            $out += [
                'release' => $read['release'] ?? null,
                'auto_deploy' => $this->autoDeploy(),
                'scheduler' => $read['scheduler'] ?? null,
                'stopped_workers' => array_values(array_filter((array) ($read['workers'] ?? []), fn (array $w): bool => ! ($w['ok'] ?? true))),
                'queue_waits' => $read['queue_waits'] ?? null,
                'error_groups' => $read['error_groups'] ?? [],
                'account_counts' => $read['account_counts'] ?? null,
                'suspicious_data' => $read['suspicious_data'] ?? null,
            ];
        } catch (Throwable $exception) {
            report($exception);
            $out['health_error'] = mb_substr($exception->getMessage(), 0, 300);
        }
        try {
            $out['alerts'] = collect($this->triage->groups())->map(fn (array $groups): array => array_map(fn (array $group): array => [
                'title' => $group['title'], 'count' => $group['count'],
                'example' => array_intersect_key((array) ($group['items'][0] ?? []), array_flip(['title', 'what', 'action', 'since'])),
            ], array_slice($groups, 0, 15)))->all();
        } catch (Throwable $exception) {
            report($exception);
            $out['alerts_error'] = mb_substr($exception->getMessage(), 0, 300);
        }
        $out['failed_ai_tasks'] = AiTask::query()->where('status', AiTask::FAILED)->where('updated_at', '>=', now()->subDays(7))
            ->latest('id')->limit(20)->get(['id', 'operation', 'subject', 'error', 'updated_at'])
            ->map(fn (AiTask $task): array => ['id' => $task->id, 'operation' => $task->operation, 'subject' => $task->subject, 'reason' => $task->error])->all();
        $out['failed_writes'] = $this->failedWrites();

        return Response::json($out);
    }

    /**
     * Approved external writes (İşletme Profili posts and replies, WordPress, Google Ads list) that failed in the last 3
     * days, and automatic posts that never reached a write, with their reasons.
     *
     * @return list<array<string, mixed>>
     */
    private function failedWrites(): array
    {
        $since = now()->subDays(3);
        $writes = ExternalWriteAction::query()->where('status', 'failed')->where('updated_at', '>=', $since)->latest('id')->limit(20)
            ->get(['id', 'channel', 'action', 'digital_asset_id', 'error', 'updated_at'])
            ->map(fn (ExternalWriteAction $a): array => ['kind' => 'write', 'id' => $a->id, 'channel' => $a->channel, 'action' => $a->action,
                'asset_id' => $a->digital_asset_id, 'reason' => mb_substr((string) $a->error, 0, 400), 'at' => (string) $a->updated_at]);
        $posts = GbpQueuedPost::query()->where('status', GbpQueuedPost::FAILED)->where('updated_at', '>=', $since)->latest('id')->limit(20)
            ->get(['id', 'digital_asset_id', 'publish_on', 'note', 'updated_at'])
            ->map(fn (GbpQueuedPost $p): array => ['kind' => 'gbp_post', 'id' => $p->id, 'asset_id' => $p->digital_asset_id, 'day' => substr((string) $p->publish_on, 0, 10),
                'reason' => mb_substr((string) $p->note, 0, 400), 'at' => (string) $p->updated_at]);

        return [...$writes->all(), ...$posts->all()];
    }

    /** @return array<string, mixed>|null the last automatic deploy check, with the failing tests when it stopped on them */
    private function autoDeploy(): ?array
    {
        $status = AutoDeployStatus::current();
        $failure = $status !== null && $status['state'] === 'tests_failed' ? AutoDeployStatus::failure(4000) : null;

        return $status === null ? null : $status + ($failure !== null ? ['failure' => $failure] : []);
    }
}
