<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Services\Ai\AiBudget;
use App\Services\DataStatus\ConnectionHealth;
use App\Services\Observability\ErrorTriage;
use App\Services\Operations\AutoDeployStatus;
use App\Services\Operations\ServerStatusReader;
use App\Services\Operations\SystemHealthReader;
use App\Services\Repair\RepairDesk;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Reads how the system is doing, from stored state only: server memory, swap, disks (bytes total/used/free) and load, software health lines (release, deploy, 24h errors and failed jobs, migrations, scheduler, workers, debug, caches, database size), the largest tables (partitions and indexes counted under the parent), deployed release, the last automatic deploy check (with the failing tests when it stopped), scheduler and stopped workers, queue waits, top application errors of the last 7 days (class, file:line, count), open alerts grouped like the Hata merkezi (you = needs the operator, code = software error, auto = heals by itself), connection health per brand (connections: what the system repairs nightly vs what needs the operator), AI tasks you could not do, approved external writes / automatic Business Profile posts that failed in the last 3 days with their reasons, and the Onarım masası writes of the last 24 hours (desk_writes, with rows still queued for approval). Use it to find bugs to report or fix and improvements to propose; it changes nothing.')]
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
            $status = app(ServerStatusReader::class)->read();
            $out['server'] = $status['server'];
            $out['software'] = $status['software'];
            $out['largest_tables'] = app(ServerStatusReader::class)->largestTables();
        } catch (Throwable $exception) {
            report($exception);
            $out['server_error'] = mb_substr($exception->getMessage(), 0, 300);
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
        try {
            $issues = app(ConnectionHealth::class)->issues();
            $out['connections'] = ['summary' => app(ConnectionHealth::class)->summary($issues),
                'issues' => array_map(fn (array $i): array => array_intersect_key($i, array_flip(['brand', 'asset', 'kind', 'who', 'title', 'detail'])), array_slice($issues, 0, 60))];
        } catch (Throwable $exception) {
            report($exception);
            $out['connections_error'] = mb_substr($exception->getMessage(), 0, 300);
        }
        $out['failed_ai_tasks'] = AiTask::query()->where('status', AiTask::FAILED)->where('updated_at', '>=', now()->subDays(7))
            ->latest('id')->limit(20)->get(['id', 'operation', 'subject', 'error', 'updated_at'])
            ->map(fn (AiTask $task): array => ['id' => $task->id, 'operation' => $task->operation, 'subject' => $task->subject, 'reason' => $task->error])->all();
        $out['failed_writes'] = $this->failedWrites();
        $out['desk_writes'] = $this->deskWrites();
        $out['ai_money'] = app(AiBudget::class)->moneyState();
        $out['ai_tasks_waiting'] = AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count();

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

    /**
     * Onarım masası approvals of the last 24 hours: each write the desk asked for (status, brand, error) and the rows
     * approved but still waiting for ApproveRepairRowsJob, to tell whether an approval went through.
     *
     * @return array{queued_rows: int, writes: list<array<string, mixed>>}
     */
    private function deskWrites(): array
    {
        $writes = ExternalWriteAction::query()->whereNotNull('suggestion_id')->where('created_at', '>=', now()->subDay())->latest('id')->limit(60)
            ->get(['id', 'channel', 'action', 'brand_id', 'digital_asset_id', 'suggestion_id', 'status', 'error', 'created_at', 'updated_at'])
            ->map(fn (ExternalWriteAction $a): array => ['id' => $a->id, 'channel' => $a->channel, 'action' => $a->action, 'brand_id' => $a->brand_id,
                'asset_id' => $a->digital_asset_id, 'suggestion_id' => $a->suggestion_id, 'status' => $a->status,
                'error' => $a->error !== null ? mb_substr((string) $a->error, 0, 200) : null, 'created_at' => (string) $a->created_at, 'updated_at' => (string) $a->updated_at]);

        return ['queued_rows' => app(RepairDesk::class)->queuedCount(), 'writes' => $writes->all()];
    }

    /** @return array<string, mixed>|null the last automatic deploy check, with the failing tests when it stopped on them */
    private function autoDeploy(): ?array
    {
        $status = AutoDeployStatus::current();
        $failure = $status !== null && in_array($status['state'], ['tests_failed', 'deploy_failed'], true) ? AutoDeployStatus::failure(4000) : null;

        return $status === null ? null : $status + ($failure !== null ? ['failure' => $failure] : []);
    }
}
