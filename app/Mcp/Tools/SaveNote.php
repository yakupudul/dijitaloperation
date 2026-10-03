<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\ClaudeNote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Saves one of your own working notes so a later run (or another Claude account) continues from it. kind: observation (what the data shows, with refs), hypothesis (a guess to check), proposal (something to suggest to the operator), followup (check again later). Append-only: to change a view, write a new note with supersedes_id; status=done closes a note. Notes never change facts, operator decisions or prompts. Write in Turkish, one point per note.')]
class SaveNote extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(ClaudeNote::KINDS)],
            'text' => ['required', 'string', 'min:5', 'max:2000'],
            'refs' => ['nullable', 'array', 'max:10'],
            'refs.*' => ['string', 'max:300'],
            'supersedes_id' => ['nullable', 'integer'],
            'close_id' => ['nullable', 'integer'],
        ]);
        $brandId = $validated['brand_id'] ?? null;
        if ($brandId !== null && ! Brand::query()->whereKey($brandId)->exists()) {
            return Response::error('Marka bulunamadı.');
        }
        foreach (['supersedes_id', 'close_id'] as $field) {
            $id = $validated[$field] ?? null;
            if ($id !== null && ! ClaudeNote::query()->whereKey($id)->where('brand_id', $brandId)->exists()) {
                return Response::error('Not #'.$id.' bu markada yok.');
            }
        }
        $note = ClaudeNote::query()->create([
            'brand_id' => $brandId, 'kind' => $validated['kind'], 'text' => trim($validated['text']),
            'refs' => array_values((array) ($validated['refs'] ?? [])) ?: null, 'status' => ClaudeNote::OPEN, 'supersedes_id' => $validated['supersedes_id'] ?? null,
        ]);
        if (($validated['supersedes_id'] ?? null) !== null) {
            ClaudeNote::query()->whereKey($validated['supersedes_id'])->update(['status' => ClaudeNote::SUPERSEDED]);
        }
        if (($validated['close_id'] ?? null) !== null) {
            ClaudeNote::query()->whereKey($validated['close_id'])->update(['status' => ClaudeNote::DONE]);
        }

        return Response::text('Not #'.$note->id.' kaydedildi.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('The brand; omit for a system-wide note.'),
            'kind' => $schema->string()->enum(ClaudeNote::KINDS)->description('observation | hypothesis | proposal | followup')->required(),
            'text' => $schema->string()->description('The note in Turkish, one point.')->required(),
            'refs' => $schema->array()->items($schema->string())->description('Where it comes from: URLs, cluster names, file sections, task ids.'),
            'supersedes_id' => $schema->integer()->description('An earlier note this one replaces.'),
            'close_id' => $schema->integer()->description('An earlier note this one closes (done).'),
        ];
    }
}
