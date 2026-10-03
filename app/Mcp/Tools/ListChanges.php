<?php

namespace App\Mcp\Tools;

use App\Models\SystemChange;
use App\Services\Operations\ReleaseInfo;
use App\Services\Operations\SystemChangeDesk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Geliştirme havuzu: lists changes of MoxDOP itself. status=todo (default) returns the work for you: approved / in_progress (code them) and deployed (check them on the live system). Other filters: proposed, ready, verified, failed, rejected, all. Also returns the live release SHA; ready changes whose commit is live are marked deployed first.')]
class ListChanges extends Tool
{
    public function handle(Request $request, SystemChangeDesk $desk): Response
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['todo', 'all', ...array_keys(SystemChange::STATUS_LABELS)])]]);
        $desk->markLiveReleases();
        $status = $validated['status'] ?? 'todo';
        $statuses = match ($status) {
            'todo' => [SystemChange::APPROVED, SystemChange::IN_PROGRESS, SystemChange::DEPLOYED],
            'all' => array_keys(SystemChange::STATUS_LABELS),
            default => [$status],
        };
        $changes = SystemChange::query()->whereIn('status', $statuses)->orderBy('priority')->orderBy('id')->limit(100)->get();

        return Response::json([
            'live_release' => ReleaseInfo::current(),
            'counts' => $desk->counts()->all(),
            'changes' => $changes->map(fn (SystemChange $change): array => $change->toTool())->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->description('todo (default) | proposed | approved | in_progress | ready | deployed | verified | failed | rejected | all')];
    }
}
