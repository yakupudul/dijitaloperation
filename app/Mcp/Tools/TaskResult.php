<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Services\AiTasks\ResultCorrections;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reads an AI task you already answered (done / consumed) and where its answer landed: the desk or content rows (suggestion) and planned Business Profile posts (gbp_post) with each correctable field (path → text, sent). Or read one row directly with suggestion_id / gbp_post_id. Use it before correct-result. Read-only.')]
class TaskResult extends Tool
{
    public function handle(Request $request, ResultCorrections $corrections): Response
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'], 'suggestion_id' => ['nullable', 'integer'], 'gbp_post_id' => ['nullable', 'integer'],
        ]);
        if (isset($validated['suggestion_id'])) {
            $suggestion = Suggestion::query()->find($validated['suggestion_id']);

            return $suggestion === null ? Response::error('Satır bulunamadı.') : Response::json($corrections->describe($suggestion));
        }
        if (isset($validated['gbp_post_id'])) {
            $post = GbpQueuedPost::query()->find($validated['gbp_post_id']);

            return $post === null ? Response::error('Gönderi bulunamadı.') : Response::json($corrections->describe($post));
        }
        $task = isset($validated['id']) ? AiTask::query()->with('brand:id,name')->find($validated['id']) : null;
        if ($task === null) {
            return Response::error('Görev bulunamadı (id, suggestion_id ya da gbp_post_id verin).');
        }

        return Response::json([
            'id' => $task->id, 'operation' => $task->operation, 'label' => AiOperationLabels::for($task->operation),
            'brand' => $task->brand?->name, 'subject' => $task->subject, 'status' => $task->status,
            'completed_at' => $task->completed_at?->toIso8601String(), 'output' => $task->output,
            'targets' => $task->isOpen() ? [] : $corrections->targetsOf($task),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('AI task id (also closed ones).'),
            'suggestion_id' => $schema->integer()->description('Or: one desk / content row.'),
            'gbp_post_id' => $schema->integer()->description('Or: one planned Business Profile post.'),
        ];
    }
}
