<?php

namespace App\Mcp\Tools;

use App\Models\ScreenCheck;
use App\Services\Operations\ScreenChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Sayfa taraması: the latest render of every operator screen (rendered nightly and after each deploy as an Admin inside MoxDOP): path, label, HTTP status, time (ms), query count, error (exception @ file:line) and the release it ran on, broken and slow screens first. With path: that screen\'s text outline (headings, notices, table columns, buttons) to review its design and wording. Read-only.')]
#[IsReadOnly]
class ScreenChecks extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:300']]);
        if (($validated['path'] ?? null) !== null) {
            $check = ScreenCheck::query()->where('path', $validated['path'])->first();

            return $check === null ? Response::error('Bu ekran taranmadı.') : Response::json($this->row($check) + ['outline' => $check->outline]);
        }
        $rows = ScreenCheck::query()->get()
            ->sortBy([fn (ScreenCheck $a, ScreenCheck $b): int => (int) $b->failed() <=> (int) $a->failed(), fn (ScreenCheck $a, ScreenCheck $b): int => $b->duration_ms <=> $a->duration_ms])
            ->values();

        return Response::json([
            'thresholds' => ['slow_ms' => ScreenChecker::SLOW_MS, 'queries' => ScreenChecker::QUERY_LIMIT],
            'screens' => $rows->map(fn (ScreenCheck $check): array => $this->row($check))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(ScreenCheck $check): array
    {
        return [
            'path' => $check->path, 'label' => $check->label, 'status' => $check->status, 'ms' => $check->duration_ms,
            'queries' => $check->queries, 'error' => $check->error, 'release' => $check->release, 'checked_at' => $check->checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['path' => $schema->string()->description('One screen path from the list, for its outline.')];
    }
}
