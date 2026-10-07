<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Otomatik kur" run for a Brand: proposed assets, resource bindings and services.
 * Nothing is applied until the operator approves (one click).
 */
class BrandSetupProposal extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_BUILDING = 'building';

    public const string STATUS_READY = 'ready';

    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_BUILDING], true);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'services' => 'array',
            'summary' => 'array',
            'apply_result' => 'array',
            'applied_at' => 'datetime',
            'auto_apply' => 'boolean',
        ];
    }

    /** Queued / building but untouched for 15 minutes: the worker died or the job was lost (waiting for Claude is not). */
    public function isStuck(): bool
    {
        return $this->isPending() && ! $this->waitsForClaude() && $this->updated_at !== null && $this->updated_at->lt(now()->subMinutes(15));
    }

    /** The build waits for Claude's answer (MCP queue); the job runs again when it is in. */
    public function waitsForClaude(): bool
    {
        return $this->isPending() && data_get($this->summary, 'waiting') === 'claude';
    }

    /**
     * Stored asset/binding proposals with every key the page and the applier read, whatever shape an older build or a
     * hand-edited row left behind. Rows without a usable key are dropped.
     *
     * @return list<array{key: string, kind: string, group: string, label: string, asset_id: ?int, url: string, status: string, target: string, resource_id: ?int, capability: ?string, confidence: float, reason: string, selected: bool}>
     */
    public function itemRows(): array
    {
        $rows = [];
        foreach (is_array($this->items) ? $this->items : [] as $item) {
            if (! is_array($item) || ! is_scalar($item['key'] ?? null) || trim((string) $item['key']) === '') {
                continue;
            }
            $rows[] = [
                'key' => (string) $item['key'],
                'kind' => self::text($item['kind'] ?? null),
                'group' => self::text($item['group'] ?? null),
                'label' => self::text($item['label'] ?? null) ?: (string) $item['key'],
                'asset_id' => self::id($item['asset_id'] ?? null),
                'url' => self::text($item['url'] ?? null),
                'status' => self::text($item['status'] ?? null) ?: 'proposed',
                'target' => self::text($item['target'] ?? null),
                'resource_id' => self::id($item['resource_id'] ?? null),
                'capability' => is_string($item['capability'] ?? null) ? $item['capability'] : null,
                'confidence' => is_numeric($item['confidence'] ?? null) ? max(0.0, min(1.0, (float) $item['confidence'])) : 0.0,
                'reason' => self::text($item['reason'] ?? null),
                'selected' => (bool) ($item['selected'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Stored service proposals, keyed by their original index (the page's checkboxes use it). Rows without a usable
     * name are dropped; names and phrases are trimmed and cut to what the offering / catalog columns hold.
     *
     * @return array<int, array{name: string, aliases: list<string>, matching_phrases: list<string>, keywords: list<array{query: string, impressions: int}>, catalog_item_id: ?int, is_new: bool, sector_code: ?string, sector_label: ?string, is_core: bool, evidence: string, status: string, selected: bool}>
     */
    public function serviceRows(): array
    {
        $rows = [];
        foreach (is_array($this->services) ? $this->services : [] as $index => $service) {
            $name = is_array($service) && is_scalar($service['name'] ?? null) ? mb_substr(trim((string) $service['name']), 0, 160) : '';
            if (! is_int($index) || mb_strlen($name) < 2) {
                continue;
            }
            $keywords = [];
            foreach (is_array($service['keywords'] ?? null) ? $service['keywords'] : [] as $keyword) {
                $query = is_array($keyword) ? ($keyword['query'] ?? null) : $keyword;
                if (is_scalar($query) && trim((string) $query) !== '') {
                    $impressions = is_array($keyword) && is_numeric($keyword['impressions'] ?? null) ? (int) $keyword['impressions'] : 0;
                    $keywords[] = ['query' => mb_substr(trim((string) $query), 0, 255), 'impressions' => $impressions];
                }
            }
            $rows[$index] = [
                'name' => $name,
                'aliases' => self::strings($service['aliases'] ?? [], 160),
                'matching_phrases' => self::strings($service['matching_phrases'] ?? [], 255),
                'keywords' => $keywords,
                'catalog_item_id' => self::id($service['catalog_item_id'] ?? null),
                'is_new' => (bool) ($service['is_new'] ?? ! isset($service['catalog_item_id'])),
                'sector_code' => is_string($service['sector_code'] ?? null) && trim($service['sector_code']) !== '' ? mb_substr(trim($service['sector_code']), 0, 120) : null,
                'sector_label' => is_string($service['sector_label'] ?? null) ? $service['sector_label'] : null,
                'is_core' => (bool) ($service['is_core'] ?? false),
                'evidence' => self::text($service['evidence'] ?? null),
                'status' => in_array($service['status'] ?? null, ['proposed', 'already'], true) ? $service['status'] : 'proposed',
                'selected' => (bool) ($service['selected'] ?? false),
            ];
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return list<string> */
    private static function strings(mixed $values, int $max): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $value = is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
