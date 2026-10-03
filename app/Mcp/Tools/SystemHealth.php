<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\Observability\ErrorTriage;
use App\Services\Operations\SystemHealthReader;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Reads how the system is doing, from stored state only: deployed release, scheduler and stopped workers, queue waits, top application errors of the last 7 days (class, file:line, count), open alerts grouped like the Hata merkezi (you = needs the operator, code = software error, auto = heals by itself) and AI tasks you could not do. Use it to find bugs to report or fix and improvements to propose; it changes nothing.')]
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

        return Response::json($out);
    }
}
