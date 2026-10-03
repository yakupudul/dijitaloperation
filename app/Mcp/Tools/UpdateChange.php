<?php

namespace App\Mcp\Tools;

use App\Models\SystemChange;
use App\Services\Operations\SystemChangeDesk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Geliştirme havuzu: moves a change you work on. step=start (approved → in_progress), step=ready (code pushed: commit SHA + the exact deploy commands for the operator; several changes of one push share the commit), step=give_back (cannot be done as asked: back to the operator with your reason), step=verified / step=failed (after deploy: what you checked on the live system and the result). Only approved changes are coded; you never deploy.')]
class UpdateChange extends Tool
{
    public function handle(Request $request, SystemChangeDesk $desk): Response
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'step' => ['required', Rule::in(['start', 'ready', 'give_back', 'verified', 'failed'])],
            'commit' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:120'],
            'deploy_commands' => ['nullable', 'string', 'max:4000'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);
        $change = SystemChange::query()->find($validated['id']);
        if ($change === null) {
            return Response::error('Değişiklik #'.$validated['id'].' yok.');
        }
        $note = $validated['note'] ?? null;
        try {
            match ($validated['step']) {
                'start' => $desk->start($change, $note),
                'ready' => $desk->ready($change, strtolower((string) ($validated['commit'] ?? '')), (string) ($validated['deploy_commands'] ?? ''), $note, $validated['branch'] ?? null),
                'give_back' => $desk->giveBack($change, (string) ($note ?? 'Claude geri verdi.')),
                'verified' => $desk->verify($change, true, (string) ($note ?? '')),
                'failed' => $desk->verify($change, false, (string) ($note ?? '')),
            };
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        }

        return Response::text('#'.$change->id.' → '.(SystemChange::STATUS_LABELS[$change->fresh()->status] ?? $change->status).'.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Change id from list-changes.')->required(),
            'step' => $schema->string()->enum(['start', 'ready', 'give_back', 'verified', 'failed'])->required(),
            'commit' => $schema->string()->description('step=ready: the pushed commit SHA.'),
            'branch' => $schema->string()->description('step=ready: the branch (default claude/project-thread-e5yimf).'),
            'deploy_commands' => $schema->string()->description('step=ready: the exact commands the operator runs, one per line.'),
            'note' => $schema->string()->description('Turkish: what was done / why given back / what was checked.'),
        ];
    }
}
