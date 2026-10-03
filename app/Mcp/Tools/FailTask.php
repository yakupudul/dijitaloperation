<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Marks an AI task as not doable from its input, with a short reason in Turkish the operator will read. The MoxDOP job continues and shows the operation as failed.')]
class FailTask extends Tool
{
    public function __construct(private readonly AiTaskQueue $queue) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $task = AiTask::query()->find($validated['id']);
        if ($task === null) {
            return Response::error('Görev bulunamadı.');
        }
        if (! $this->queue->fail($task, $validated['reason'])) {
            return Response::error('Bu görev artık açık değil (durum: '.$task->status.').');
        }

        return Response::text('Görev '.$task->id.' yapılamadı olarak işaretlendi.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Task id.')->required(),
            'reason' => $schema->string()->description('Why it cannot be done (Turkish, one or two sentences).')->required(),
        ];
    }
}
