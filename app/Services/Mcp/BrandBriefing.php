<?php

namespace App\Services\Mcp;

use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\ClaudeNote;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandDossier;

/**
 * What Claude reads about a brand over MCP, in two layers that never mix:
 *
 *  - facts: the brand file (BrandDossier), built by rules from MoxDOP's own data, no AI; each section has a hash, so
 *    Claude reads only what changed since its last read (the cursor is stored here, not in a Claude account);
 *  - claude_notes: Claude's own dated notes (observation / hypothesis / proposal / follow-up), append-only.
 *
 * A note never edits a fact, an operator decision or a prompt; it is context for the next Claude run only.
 */
final class BrandBriefing
{
    public const string SEEN_KIND = 'claude_seen';

    private const int NOTES = 20;

    public function __construct(private readonly BrandDossier $dossier) {}

    /**
     * Operational brands with what changed since Claude last read their file.
     *
     * @return list<array<string, mixed>>
     */
    public function brands(bool $onlyChanged = false): array
    {
        $brands = Brand::query()->operational()->with('sectorCategory:id,name')->orderBy('name')->get(['id', 'name', 'sector_id', 'customer_id']);
        $ids = $brands->pluck('id')->all() ?: [0];
        $sites = DigitalAsset::query()->where('type', 'website')->whereIn('brand_id', $ids)->get(['id', 'brand_id', 'name', 'primary_url'])->groupBy('brand_id');
        $notes = ClaudeNote::query()->whereIn('brand_id', $ids)->where('status', ClaudeNote::OPEN)->selectRaw('brand_id, count(*) as c')->groupBy('brand_id')->pluck('c', 'brand_id');
        $tasks = AiTask::query()->whereIn('brand_id', $ids)->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->selectRaw('brand_id, count(*) as c')->groupBy('brand_id')->pluck('c', 'brand_id');

        $rows = [];
        foreach ($brands as $brand) {
            $stored = BrandDossier::stored($brand);
            $changed = $stored === null ? [] : BrandDossier::changedSince($brand, $this->seen($brand));
            if ($onlyChanged && $stored !== null && $changed === []) {
                continue;
            }
            $rows[] = [
                'id' => $brand->id,
                'name' => $brand->name,
                'sector' => $brand->sectorCategory?->name,
                'websites' => ($sites[$brand->id] ?? collect())->map(fn (DigitalAsset $s): array => ['id' => $s->id, 'url' => $s->primary_url ?? $s->name])->values()->all(),
                'file_built_at' => $stored['built_at'] ?? null,
                'never_read' => $this->seen($brand) === [],
                'changed_sections' => $changed,
                'open_notes' => (int) ($notes[$brand->id] ?? 0),
                'waiting_tasks' => (int) ($tasks[$brand->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The brand file (facts) and Claude's open notes; marks the sections as read.
     *
     * @param  list<string>  $sections  only these section keys (empty = all)
     * @return array<string, mixed>
     */
    public function brand(Brand $brand, array $sections = [], bool $onlyChanged = false, bool $rebuild = false): array
    {
        $stored = $rebuild ? $this->dossier->build($brand) : (BrandDossier::stored($brand) ?? $this->dossier->build($brand));
        $seen = $this->seen($brand);
        $out = [];
        foreach ((array) $stored['sections'] as $key => $section) {
            $changed = ($seen[$key] ?? null) !== ($section['hash'] ?? null);
            if (($sections !== [] && ! in_array($key, $sections, true)) || ($onlyChanged && ! $changed)) {
                continue;
            }
            $out[$key] = ['title' => $section['title'] ?? $key, 'changed_since_last_read' => $changed, 'markdown' => $section['markdown'] ?? ''];
        }
        $this->markSeen($brand, $seen, array_map(fn (array $s): string => (string) ($s['hash'] ?? ''), array_intersect_key((array) $stored['sections'], $out)));

        return [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'facts' => ['source' => 'Marka dosyası: MoxDOP verisinden kurallarla derlenir, AI yorumu yok.', 'built_at' => $stored['built_at'] ?? null, 'sections' => $out],
            'claude_notes' => ClaudeNote::query()->where('brand_id', $brand->id)->where('status', ClaudeNote::OPEN)->latest('id')->limit(self::NOTES)->get()
                ->map(fn (ClaudeNote $note): array => $note->toTool())->all(),
        ];
    }

    /** @return array<string, string> section => hash Claude last read */
    private function seen(Brand $brand): array
    {
        return (array) (BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::SEEN_KIND)->value('data') ?? []);
    }

    /**
     * @param  array<string, string>  $seen
     * @param  array<string, string>  $read
     */
    private function markSeen(Brand $brand, array $seen, array $read): void
    {
        if ($read === []) {
            return;
        }
        BrandMemory::query()->updateOrCreate(['brand_id' => $brand->id, 'kind' => self::SEEN_KIND, 'ref_type' => 'claude', 'ref_id' => null],
            ['data' => $read + $seen]);
    }
}
