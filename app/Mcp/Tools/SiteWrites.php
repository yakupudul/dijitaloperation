<?php

namespace App\Mcp\Tools;

use App\Models\ExternalWriteAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Siteyi düzenleme: the writes to WordPress sites (edit-site, approved Onarım masası rows, content sends), newest first: action_id, site, what, status, error and per-change results (change_id, before, ok). Filter by site_id or ids.')]
#[IsReadOnly]
class SiteWrites extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'ids' => ['nullable', 'array', 'max:50'], 'ids.*' => ['integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $rows = ExternalWriteAction::query()->with('digitalAsset:id,name')->where('channel', ExternalWriteAction::CHANNEL_WORDPRESS)
            ->when(isset($validated['site_id']), fn ($q) => $q->where('digital_asset_id', (int) $validated['site_id']))
            ->when(! empty($validated['ids']), fn ($q) => $q->whereIn('id', array_map('intval', $validated['ids'])))
            ->latest('id')->limit((int) ($validated['limit'] ?? 20))->get();

        return Response::json(['writes' => $rows->map(fn (ExternalWriteAction $a): array => [
            'action_id' => $a->id, 'site_id' => $a->digital_asset_id, 'site' => $a->digitalAsset?->name, 'action' => $a->action,
            'status' => $a->status, 'status_label' => $a->statusLabel(), 'undoable' => $a->isUndoable(), 'error' => $a->error,
            'at' => $a->created_at?->timezone('Europe/Istanbul')->format('Y-m-d H:i'),
            'result' => collect((array) $a->result)->only(['changes', 'applied', 'failed', 'post_id', 'edit_url', 'preview_url', 'url', 'waiting_since'])->all(),
        ])->values()->all()]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('Only this site.'),
            'ids' => $schema->array()->items($schema->integer())->description('Only these action ids.'),
            'limit' => $schema->integer()->description('Max 50 (default 20).'),
        ];
    }
}
