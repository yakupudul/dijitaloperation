<?php

namespace App\Services\Queries;

use App\Models\ServiceCategory;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The core query store (search_query_library_items): finds or creates the core query of each normalized text by its
 * folded identity (case / ı-i / diacritics insensitive, operator renames honoured through library_query_aliases),
 * files it under the source account's sector and keeps the aggregated metrics of its raw variants.
 * A core query removed by the operator (soft-deleted) is never recreated.
 */
final class CoreQueryStore
{
    public const string IDENTITY_PREFIX = 'library-location-free-v2|';

    /** Core queries created by this instance (the pipeline reports "N yeni sorgu"). */
    private int $created = 0;

    public function __construct(private readonly SearchTermNormalizer $normalizer) {}

    public function createdCount(): int
    {
        return $this->created;
    }

    /**
     * @param  array<string, string>  $cores  core key => canonical core text
     * @return array<string, array{id: int, trashed: bool}>
     */
    public function resolve(array $cores, ?string $sector): array
    {
        if ($cores === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_keys($cores), 500) as $chunk) {
            DB::table('search_query_library_items')->whereIn('core_key', $chunk)
                ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')->orderBy('id')
                ->get(['id', 'core_key', 'deleted_at'])->each(function ($row) use (&$out): void {
                    $out[(string) $row->core_key] ??= ['id' => (int) $row->id, 'trashed' => $row->deleted_at !== null];
                });
        }
        $missing = array_diff_key($cores, $out);
        if ($missing !== []) {
            $hashes = [];
            foreach ($missing as $key => $text) {
                $hashes[hash('sha256', self::IDENTITY_PREFIX.$text)] = $key;
            }
            foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
                $aliases = DB::table('library_query_aliases')->whereIn('identity_hash', $chunk)->pluck('query_id', 'identity_hash');
                $direct = DB::table('search_query_library_items')->whereIn('identity_hash', $chunk)->pluck('id', 'identity_hash');
                foreach ($aliases->union($direct) as $hash => $id) {
                    $trashed = DB::table('search_query_library_items')->where('id', $id)->whereNotNull('deleted_at')->exists();
                    $out[$hashes[$hash]] ??= ['id' => (int) $id, 'trashed' => $trashed];
                }
            }
        }
        $now = now();
        foreach (array_diff_key($cores, $out) as $key => $text) {
            $identity = hash('sha256', self::IDENTITY_PREFIX.$text);
            try {
                $id = DB::table('search_query_library_items')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'identity_hash' => $identity, 'core_key' => $key,
                    'canonical_text' => $text, 'folded_text' => $this->normalizer->normalize($text, 'tr')->foldedText,
                    'language_code' => 'tr', 'sector' => $sector, 'location_scope' => 'none', 'is_branded' => false, 'status' => 'active',
                    'normalization_version' => QueryNormalizer::VERSION, 'classification_source' => 'pipeline',
                    'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->created++;
            } catch (UniqueConstraintViolationException) {
                $id = (int) DB::table('search_query_library_items')->where('identity_hash', $identity)->value('id');
            }
            $out[$key] = ['id' => (int) $id, 'trashed' => false];
        }
        $this->fileUnder(array_values(array_map(fn (array $r): int => $r['id'], array_filter($out, fn (array $r): bool => ! $r['trashed']))), $sector);

        return $out;
    }

    /**
     * Files core queries under a sector (pending service matching) and fills the legacy scalar sector.
     *
     * @param  list<int>  $ids
     */
    public function fileUnder(array $ids, ?string $sector): void
    {
        if ($ids === [] || $sector === null) {
            return;
        }
        $categoryId = ServiceCategory::query()->where('code', $sector)->value('id');
        if ($categoryId === null) {
            return;
        }
        $now = now();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('search_query_library_sectors')->insertOrIgnore(array_map(fn (int $id): array => [
                'search_query_library_item_id' => $id, 'service_category_id' => $categoryId, 'match_status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
            DB::table('search_query_library_items')->whereIn('id', $chunk)->whereNull('sector')->update(['sector' => $sector]);
        }
    }

    /**
     * Aggregated metrics of each core query from its core variants (every account, every source).
     *
     * @param  list<int>  $ids
     */
    public function refreshMetrics(array $ids): void
    {
        $now = now();
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 500) as $chunk) {
            $totals = array_fill_keys($chunk, [
                'gsc_impressions' => 0, 'gsc_clicks' => 0, 'ads_impressions' => 0, 'ads_clicks' => 0, 'ads_cost' => 0.0,
                'ads_conversions' => 0.0, 'gbp_impressions' => 0, 'variant_count' => 0,
            ]);
            $rows = DB::table('query_variants')->whereIn('search_query_library_item_id', $chunk)->where('kind', QueryNormalization::CORE)
                ->groupBy('search_query_library_item_id', 'source')
                ->selectRaw('search_query_library_item_id as id, source, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost) as cost, sum(conversions) as conversions, count(*) as variants')
                ->get();
            foreach ($rows as $row) {
                $t = &$totals[(int) $row->id];
                $t['variant_count'] += (int) $row->variants;
                match ($row->source) {
                    'search_console' => [$t['gsc_impressions'] += (int) $row->impressions, $t['gsc_clicks'] += (int) $row->clicks],
                    'google_ads' => [$t['ads_impressions'] += (int) $row->impressions, $t['ads_clicks'] += (int) $row->clicks,
                        $t['ads_cost'] += (float) $row->cost, $t['ads_conversions'] += (float) $row->conversions],
                    'google_business_profile' => [$t['gbp_impressions'] += (int) $row->impressions],
                    default => null,
                };
                unset($t);
            }
            $this->updateTotals($totals, $now);
        }
    }

    /**
     * One UPDATE … SET col = CASE id … per chunk instead of one statement per core query (5 000 queries of a large
     * account were 5 000 round trips).
     *
     * @param  array<int, array<string, int|float>>  $totals
     */
    private function updateTotals(array $totals, mixed $now): void
    {
        if ($totals === []) {
            return;
        }
        $ids = array_keys($totals);
        $columns = array_keys(reset($totals));
        $sets = [];
        $bindings = [];
        foreach ($columns as $column) {
            $case = 'CASE id';
            foreach ($totals as $id => $values) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = (int) $id;
                $bindings[] = $values[$column];
            }
            $sets[] = $column.' = '.$case.' ELSE '.$column.' END';
        }
        $sets[] = 'metrics_at = ?';
        $bindings[] = $now;
        $bindings = [...$bindings, ...$ids];
        DB::update('UPDATE search_query_library_items SET '.implode(', ', $sets).' WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')', $bindings);
    }
}
