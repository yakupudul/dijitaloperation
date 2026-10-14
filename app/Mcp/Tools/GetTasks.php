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

/**
 * Paket alma: up to 50 waiting tasks of one operation in one call, claimed together. Instructions and output schemas
 * are sent once per distinct text (keyed by a short hash); tasks only carry their hash, so a batch of 40 triage tasks
 * reads the long prompt once instead of 40 times. Hashes the caller already holds (`known`) are not sent again.
 */
#[Description('Reads and claims up to 50 waiting tasks of ONE operation at once (oldest first). Instructions and output schemas come once in `instructions` / `schemas` keyed by hash; each task carries instructions_hash, schema_hash, input and last_errors. Pass hashes you already have in `known` to skip them. Answer with submit-results.')]
class GetTasks extends Tool
{
    public function __construct(private readonly AiTaskQueue $queue) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => ['required', 'string', 'max:80'],
            'brand_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'known' => ['nullable', 'array'],
            'known.*' => ['string', 'max:64'],
        ]);
        $known = array_flip($validated['known'] ?? []);
        $tasks = AiTask::query()->with('brand:id,name')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
            ->where('operation', $validated['operation'])
            ->when($validated['brand_id'] ?? null, fn ($q, int $brandId) => $q->where('brand_id', $brandId))
            ->orderBy('id')->limit((int) ($validated['limit'] ?? 25))->get();

        $instructions = [];
        $schemas = [];
        $rows = [];
        foreach ($tasks as $task) {
            $this->queue->claim($task);
            $instructionsHash = self::hash((string) $task->instructions);
            $schemaHash = self::hash((string) json_encode($task->output_schema));
            if (! isset($known[$instructionsHash])) {
                $instructions[$instructionsHash] = (string) $task->instructions;
            }
            if (! isset($known[$schemaHash])) {
                $schemas[$schemaHash] = $task->output_schema;
            }
            $rows[] = [
                'id' => $task->id,
                'brand' => $task->brand?->name,
                'subject' => $task->subject,
                'instructions_hash' => $instructionsHash,
                'schema_hash' => $schemaHash,
                'input' => $task->input,
                'last_errors' => $task->error,
            ];
        }

        return Response::json([
            'operation' => $validated['operation'],
            'label' => AiOperationLabels::for($validated['operation']),
            'still_waiting' => AiTask::query()->where('operation', $validated['operation'])->where('status', AiTask::PENDING)->count(),
            'instructions' => (object) $instructions,
            'schemas' => (object) $schemas,
            'tasks' => $rows,
        ]);
    }

    /** Short stable key for a prompt or schema text. */
    public static function hash(string $text): string
    {
        return substr(hash('sha256', $text), 0, 12);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('Operation key from list-tasks `by_operation`, e.g. queries.triage.')->required(),
            'brand_id' => $schema->integer()->description('Only this brand.'),
            'limit' => $schema->integer()->description('How many tasks (1-50, default 25).'),
            'known' => $schema->array()->items($schema->string())->description('instructions_hash / schema_hash values you already received in this session; they are not sent again.'),
        ];
    }
}
