<?php

namespace App\Mcp\Tools;

use App\Models\Strategy;
use App\Models\StrategyVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Reads Stratejiler. Without strategy_id: the list (newest first; brand_id, channel and status narrow it). With strategy_id: that strategy with its current text, evidence and version list; version reads an earlier text. Read the earlier strategies of a brand before writing a new one so the new one builds on them.')]
#[IsReadOnly]
class ListStrategies extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'strategy_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer'],
            'channel' => ['nullable', Rule::in(array_keys(Strategy::CHANNELS))],
            'status' => ['nullable', Rule::in(array_keys(Strategy::STATUSES))],
        ]);

        if (($validated['strategy_id'] ?? null) !== null) {
            $strategy = Strategy::query()->with('brand:id,name')->find($validated['strategy_id']);
            if ($strategy === null) {
                return Response::error('Strateji bulunamadı.');
            }
            $number = (int) ($validated['version'] ?? $strategy->current_version);
            $version = $strategy->versions()->where('version', $number)->first();
            if ($version === null) {
                return Response::error('Sürüm v'.$number.' yok.');
            }

            return Response::json($strategy->toTool() + [
                'request' => $strategy->request,
                'version' => $version->version,
                'body' => $version->body,
                'evidence' => $version->evidence ?? [],
                'plan' => $strategy->plan,
                'versions' => $strategy->versions()->get(['version', 'change_note', 'author', 'created_at'])
                    ->map(fn (StrategyVersion $v): array => ['version' => $v->version, 'change_note' => $v->change_note, 'author' => $v->author, 'at' => $v->created_at?->toIso8601String()])->all(),
            ]);
        }

        $rows = Strategy::query()->with('brand:id,name')
            ->when($validated['brand_id'] ?? null, fn ($q, int $id) => $q->where('brand_id', $id))
            ->when($validated['channel'] ?? null, fn ($q, string $c) => $q->where('channel', $c))
            ->when($validated['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->latest('updated_at')->limit(100)->get();

        return Response::json(['strategies' => $rows->map(fn (Strategy $s): array => $s->toTool())->all()]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'strategy_id' => $schema->integer()->description('Read one strategy in full.'),
            'version' => $schema->integer()->description('With strategy_id: an earlier version.'),
            'brand_id' => $schema->integer()->description('Only this brand.'),
            'channel' => $schema->string()->enum(array_keys(Strategy::CHANNELS)),
            'status' => $schema->string()->enum(array_keys(Strategy::STATUSES)),
        ];
    }
}
