<?php

namespace App\Mcp\Tools;

use App\Models\ClaudeNote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists your own notes (newest first): open ones by default. brand_id limits to a brand; system=true lists the system-wide ones; status=all includes done and superseded notes.')]
#[IsReadOnly]
class ListNotes extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'system' => ['nullable', 'boolean'],
            'kind' => ['nullable', Rule::in(ClaudeNote::KINDS)],
            'status' => ['nullable', Rule::in([ClaudeNote::OPEN, ClaudeNote::DONE, ClaudeNote::SUPERSEDED, 'all'])],
        ]);
        $status = $validated['status'] ?? ClaudeNote::OPEN;
        $notes = ClaudeNote::query()->with('brand:id,name')
            ->when($validated['brand_id'] ?? null, fn ($q, int $id) => $q->where('brand_id', $id))
            ->when((bool) ($validated['system'] ?? false), fn ($q) => $q->whereNull('brand_id'))
            ->when($validated['kind'] ?? null, fn ($q, string $kind) => $q->where('kind', $kind))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(50)->get();

        return Response::json(['notes' => $notes->map(fn (ClaudeNote $note): array => $note->toTool() + ['brand' => $note->brand?->name])->all()]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Only this brand.'),
            'system' => $schema->boolean()->description('Only system-wide notes (no brand).'),
            'kind' => $schema->string()->enum(ClaudeNote::KINDS),
            'status' => $schema->string()->enum([ClaudeNote::OPEN, ClaudeNote::DONE, ClaudeNote::SUPERSEDED, 'all'])->description('Default open.'),
        ];
    }
}
