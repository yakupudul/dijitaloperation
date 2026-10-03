<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reads one waiting AI task and marks it as being worked on: instructions (use as the system prompt), input (DATA_JSON data), output_schema (the JSON object submit_result must match) and the last refusal errors.')]
class GetTask extends Tool
{
    public function __construct(private readonly AiTaskQueue $queue) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);
        $task = AiTask::query()->with('brand:id,name')->find($validated['id']);
        if ($task === null) {
            return Response::error('Görev bulunamadı.');
        }
        if (! $task->isOpen()) {
            return Response::error('Bu görev artık açık değil (durum: '.$task->status.').');
        }
        $this->queue->claim($task);

        return Response::json([
            'id' => $task->id,
            'operation' => $task->operation,
            'label' => AiOperationLabels::for($task->operation),
            'brand' => $task->brand?->name,
            'subject' => $task->subject,
            'instructions' => $task->instructions,
            'input' => $task->input,
            'output_schema' => $task->output_schema,
            'last_errors' => $task->error,
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Task id from list_tasks.')->required(),
        ];
    }
}
