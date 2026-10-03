<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists AI tasks waiting for Claude (oldest first): id, operation, brand, subject, status. Optional filters: operation key, brand_id.')]
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
            ->orderBy('id')->limit((int) config('moxdop-mcp.list_limit', 20))->get();

        return Response::json([
            'waiting' => AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count(),
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
