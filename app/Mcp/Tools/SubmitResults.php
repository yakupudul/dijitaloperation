<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Paket teslim: many answers in one call. Each is checked and stored exactly like submit-result; the reply lists
 * which were stored and the schema errors of the rest, so only those are fixed and sent again.
 */
#[Description('Submits several task results at once: results = [{id, output}] (or {id, fail_reason} when a task cannot be done from its input). Each output is checked against its task schema like submit-result. Returns stored ids, failed ids and per-id errors to fix and resend.')]
class SubmitResults extends Tool
{
    public function __construct(private readonly AiTaskQueue $queue) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:50'],
            'results.*.id' => ['required', 'integer'],
            'results.*.output' => ['nullable'],
            'results.*.fail_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $tasks = AiTask::query()->whereIn('id', array_column($validated['results'], 'id'))->get()->keyBy('id');
        $stored = [];
        $failed = [];
        $errors = [];
        foreach ($validated['results'] as $result) {
            $task = $tasks->get($result['id']);
            if ($task === null) {
                $errors[$result['id']] = ['Görev bulunamadı.'];

                continue;
            }
            if (filled($result['fail_reason'] ?? null)) {
                if ($this->queue->fail($task, (string) $result['fail_reason'])) {
                    $failed[] = $task->id;
                } else {
                    $errors[$task->id] = ['Bu görev artık açık değil (durum: '.$task->status.').'];
                }

                continue;
            }
            $output = $result['output'] ?? null;
            if (is_string($output)) {
                $output = json_decode($output, true);
            }
            $taskErrors = $this->queue->submit($task, $output);
            if ($taskErrors === []) {
                $stored[] = $task->id;
            } else {
                $errors[$task->id] = $taskErrors;
            }
        }

        return Response::json(['stored' => $stored, 'failed' => $failed, 'errors' => (object) $errors]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'output' => $row->object()->description('The result object; must match the task schema.'),
                'fail_reason' => $row->string()->description('Short Turkish reason when the task cannot be done; instead of output.'),
            ]))->description('1-50 answers.')->required(),
        ];
    }
}
