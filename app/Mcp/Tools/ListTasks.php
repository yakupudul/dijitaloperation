<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\AiTasks\AiTaskPriority;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists AI tasks waiting for Claude (most urgent first: writing and repair checks, then WhatsApp / review replies, then bulk work; oldest first inside): id, operation, brand, subject, status, plus `by_operation` (waiting count per operation in the order to work them, for get-tasks) and `oldest_waiting_hours`. Optional filters: operation key, brand_id.')]
#[IsReadOnly]
class ListTasks extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => ['nullable', 'string', 'max:80'],
            'brand_id' => ['nullable', 'integer'],
        ]);
        $tasks = AiTask::query()->with('brand:id,name')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
            ->when($validated['operation'] ?? null, fn ($q, string $operation) => $q->where('operation', $operation))
            ->when($validated['brand_id'] ?? null, fn ($q, int $brandId) => $q->where('brand_id', $brandId))
            ->orderByRaw(AiTaskPriority::orderSql())->orderBy('id')->limit((int) config('moxdop-mcp.list_limit', 20))->get();
        $oldest = AiTask::query()->where('status', AiTask::PENDING)->min('created_at');

        return Response::json([
            'waiting' => AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count(),
            'oldest_waiting_hours' => $oldest !== null ? (int) floor(Carbon::parse((string) $oldest)->diffInMinutes(now()) / 60) : null,
            // Work order: writing / repair checks first, then people waiting (WhatsApp, reviews), then bulk; oldest first inside.
            'by_operation' => AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
                ->selectRaw('operation, count(*) as waiting, min(created_at) as oldest')->groupBy('operation')->get()
                ->sortBy(fn ($row): array => [AiTaskPriority::tier((string) $row->operation), (string) $row->oldest, (string) $row->operation])
                ->mapWithKeys(fn ($row): array => [(string) $row->operation => (int) $row->waiting])->all(),
            'tasks' => $tasks->map(fn (AiTask $task): array => [
                'id' => $task->id,
                'operation' => $task->operation,
                'label' => AiOperationLabels::for($task->operation),
                'brand' => $task->brand?->name,
                'subject' => $task->subject,
                'status' => $task->status,
                'attempts' => $task->attempts,
                'created_at' => $task->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('Only this operation key, e.g. site.write_article.'),
            'brand_id' => $schema->integer()->description('Only this brand.'),
        ];
    }
}
