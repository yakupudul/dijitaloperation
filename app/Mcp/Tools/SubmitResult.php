<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Submits the result of an AI task: one JSON object matching the task output_schema. MoxDOP checks it; on errors nothing is stored and the errors come back to fix and submit again. A stored result continues the MoxDOP job that asked (its own checks and the operator approval still apply).')]
class SubmitResult extends Tool
{
    public function __construct(private readonly AiTaskQueue $queue) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer'], 'output' => ['required']]);
        $task = AiTask::query()->find($validated['id']);
        if ($task === null) {
            return Response::error('Görev bulunamadı.');
        }
        $output = $validated['output'];
        if (is_string($output)) {
            $output = json_decode($output, true);
        }
        $errors = $this->queue->submit($task, $output);
        if ($errors !== []) {
            return Response::error("Sonuç şemaya uymuyor, düzeltip tekrar gönderin:\n- ".implode("\n- ", $errors));
        }

        return Response::text('Kaydedildi. Görev '.$task->id.' tamamlandı; MoxDOP işi devam ediyor.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Task id.')->required(),
            'output' => $schema->object()->description('The result object; must match the task output_schema.')->required(),
        ];
    }
}
