<?php

namespace App\Services\Ownership;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\AssetMerge;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kopya web siteleri: website assets that share a normalized host (www., scheme, path ignored — OwnershipGuard rule)
 * and were created before the model guard existed. Finds the groups, previews a merge (dry run) and merges a duplicate
 * into its keeper: every row that points at the duplicate moves to the keeper, a row that would break a unique key is
 * resolved per table rule, and the duplicate is archived (status archived + soft delete, merged_into_asset_id), never
 * hard-deleted. Cross-customer merges need an Admin's explicit confirmation and are recorded as a yetki devri.
 *
 * Table rules (schema-driven, so new tables are covered without code changes):
 * - Every table with a foreign key to digital_assets, or a digital_asset_id / website_asset_id /
 *   source_digital_asset_id column, is moved (chunked by id).
 * - A duplicate row colliding with a keeper row on a unique key: the keeper's row stays, the duplicate's is dropped
 *   (default); `ad_budget_status` / `website_sitemap_watch` keep the newer row; `wordpress_site_health` keeps the row
 *   of the side whose WordPress connector won. Rows that point at a dropped row are re-pointed to the surviving row
 *   first (same rules, up to three levels).
 * - core_asset_bindings: one binding per capability; the keeper's active binding wins, a duplicate's active binding
 *   replaces a keeper's inactive one; the losing duplicate binding stays on the archived duplicate, disabled with
 *   reason "merged".
 * - core_connections: one connector per type; the paired, enabled one wins (keeper on a tie); the loser stays on the
 *   archived duplicate, disabled.
 * - brand_id / customer_id columns (foreign keys to brands / customers) of moved rows follow the keeper's brand.
 */
final class WebsiteDuplicateMerger
{
    public const string MERGED_REASON = 'merged';

    private const int CHUNK = 500;

    private const int MAX_DEPTH = 3;

    /** @var list<string> never rewritten: the asset itself and append-only history */
    private const array SKIP_TABLES = ['digital_assets', 'asset_merges', 'ownership_transfers', 'migrations'];

    /** @var list<string> asset reference columns without a declared foreign key */
    private const array ASSET_COLUMNS = ['digital_asset_id', 'website_asset_id', 'source_digital_asset_id'];

    /** @var list<string> handled by their own rules */
    private const array CUSTOM_TABLES = ['core_asset_bindings', 'core_connections'];

    /** @var list<string> single-state tables where the most recently updated row wins */
    private const array NEWER_WINS = ['ad_budget_status', 'website_sitemap_watch'];

    /** @var list<string> tables whose row follows the WordPress connector that won */
    private const array FOLLOWS_CONNECTOR = ['wordpress_site_health'];

    /** @var list<string> collected provider facts counted for the keeper choice */
    private const array FACT_TABLES = ['gsc_query_daily', 'gsc_page_daily', 'ga4_property_daily', 'google_ads_campaign_daily', 'meta_campaign_daily', 'findings'];

    /** @var array{tables: list<string>, refs: list<array{table: string, column: string}>, children: array<string, list<array{table: string, column: string}>>}|null */
    private ?array $schema = null;

    /** @var array<string, array{columns: list<string>, has_id: bool, uniques: list<list<string>>, time: ?string, brand: ?string, customer: ?string}> */
    private array $meta = [];

    /** @var array<string, bool> */
    private array $readable = [];

    private string $currentTable = '';

    public function __construct(private readonly OwnershipTransferService $transfers) {}

    /**
     * Website assets sharing a host, largest data first inside each group, with the suggested keeper.
     *
     * @return list<array{key: int, host: string, cross_customer: bool, suggested_keeper_id: int, assets: list<array<string, mixed>>}>
     */
    public function findGroups(): array
    {
        $assets = DigitalAsset::query()->with('brand.customer')->where('type', 'website')->orderBy('id')->get()->keyBy('id');
        $parent = [];
        $hostOwner = [];
        $find = function (int $id) use (&$parent, &$find): int {
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        foreach ($assets as $asset) {
            $id = (int) $asset->id;
            $parent[$id] = $id;
            foreach ($this->hosts($asset) as $host) {
                if (isset($hostOwner[$host])) {
                    $parent[$find($id)] = $find($hostOwner[$host]);
                } else {
                    $hostOwner[$host] = $id;
                }
            }
        }

        $groups = [];
        foreach (array_keys($parent) as $id) {
            $groups[$find($id)][] = $id;
        }

        $result = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $rows = array_map(fn (int $id): array => $this->describe($assets[$id]), $ids);
            $customers = collect($rows)->pluck('customer_id')->filter()->unique();
            $result[] = [
                'key' => $ids[0],
                'host' => $this->hosts($assets[$ids[0]])[0] ?? '',
                'cross_customer' => $customers->count() > 1,
                'suggested_keeper_id' => $this->suggestKeeper($rows),
                'assets' => $rows,
            ];
        }

        return $result;
    }

    /**
     * One asset of a group: owner, age and how much data hangs on it.
     *
     * @return array<string, mixed>
     */
    public function describe(DigitalAsset $asset): array
    {
        $asset->loadMissing('brand.customer');
        $id = (int) $asset->id;
        $connection = CoreConnection::query()->where('digital_asset_id', $id)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)->orderByDesc('enabled')->first();
        $counts = [
            'bindings' => CoreAssetBinding::query()->where('digital_asset_id', $id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->count(),
            'pages' => $this->countFor('website_url', 'digital_asset_id', $id),
            'facts' => array_sum(array_map(fn (string $table): int => $this->countFor($table, 'digital_asset_id', $id), self::FACT_TABLES)),
        ];
        $customer = $asset->brand?->customer;

        return [
            'id' => $id,
            'name' => (string) $asset->name,
            'url' => (string) ($asset->primary_url ?: $asset->domain),
            'status' => $asset->status instanceof DigitalAssetStatus ? $asset->status->value : (string) $asset->status,
            'brand_id' => $asset->brand_id !== null ? (int) $asset->brand_id : null,
            'brand' => $asset->brand?->name,
            'customer_id' => $customer?->id !== null ? (int) $customer->id : null,
            'customer' => $customer?->name,
            'customer_active' => $customer !== null && $customer->status === CustomerStatus::Active,
            'created_at' => $asset->created_at?->toIso8601String(),
            'counts' => $counts,
            'connector' => $connection === null ? null : ($this->isPaired($connection) ? 'paired' : 'pending'),
            'data_total' => $counts['pages'] + $counts['facts'],
        ];
    }

    /**
     * Default keeper: brand of an active customer, then active bindings / connector, then most data, then oldest.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function suggestKeeper(array $rows): int
    {
        usort($rows, function (array $a, array $b): int {
            $score = fn (array $row): array => [
                $row['customer_active'] ? 1 : 0,
                ($row['counts']['bindings'] > 0 || $row['connector'] === 'paired') ? 1 : 0,
                (int) $row['data_total'],
            ];

            return ($score($b) <=> $score($a))
                ?: ((string) $a['created_at'] <=> (string) $b['created_at'])
                ?: ($a['id'] <=> $b['id']);
        });

        return (int) $rows[0]['id'];
    }

    /**
     * Dry run: what would move from the duplicate to the keeper and what collides. Changes nothing.
     *
     * @return array{cross_customer: bool, tables: list<array{table: string, column: string, rows: int, collisions: int, rule: string}>, bindings: list<array<string, mixed>>, connectors: list<array<string, mixed>>, move_total: int, collision_total: int}
     */
    public function plan(DigitalAsset $keeper, DigitalAsset $duplicate): array
    {
        $this->assertMergeable($keeper, $duplicate);
        $overrides = $this->ownerOverrides($keeper, $duplicate);
        $tables = [];
        foreach ($this->schemaMap()['refs'] as $ref) {
            $rows = DB::table($ref['table'])->where($ref['column'], $duplicate->id)->count();
            if ($rows === 0) {
                continue;
            }
            $set = $this->setFor($ref['table'], $ref['column'], (int) $keeper->id, $overrides);
            $collisions = 0;
            foreach ($this->collisionIndexes($ref['table'], $ref['column']) as $unique) {
                $collisions += $this->hasId($ref['table'])
                    ? $this->collisionQuery($ref['table'], $ref['column'], $unique, (int) $duplicate->id, $set)->distinct()->count('d.id')
                    : $this->collisionQuery($ref['table'], $ref['column'], $unique, (int) $duplicate->id, $set)->count();
            }
            $tables[] = [
                'table' => $ref['table'],
                'column' => $ref['column'],
                'rows' => $rows,
                'collisions' => min($rows, $collisions),
                'rule' => $this->ruleFor($ref['table']),
            ];
        }
        $bindings = $this->bindingDecisions($keeper, $duplicate);
        $connectors = $this->connectorDecisions($keeper, $duplicate);

        return [
            'cross_customer' => $this->isCrossCustomer($keeper, $duplicate),
            'tables' => $tables,
            'bindings' => array_map(fn (array $d): array => [
                'capability' => $d['binding']->capability,
                'status' => $d['binding']->status,
                'action' => $d['action'],
            ], $bindings),
            'connectors' => array_map(fn (array $d): array => [
                'type' => $d['type'],
                'winner' => $d['winner'],
            ], $connectors),
            'move_total' => array_sum(array_column($tables, 'rows')) - array_sum(array_column($tables, 'collisions'))
                + count(array_filter($bindings, fn (array $d): bool => $d['action'] !== 'disable')),
            'collision_total' => array_sum(array_column($tables, 'collisions'))
                + count(array_filter($bindings, fn (array $d): bool => $d['action'] === 'disable')),
        ];
    }

    /**
     * Moves everything from the duplicate to the keeper and archives the duplicate. One transaction; nothing changes
     * when any step fails.
     */
    public function merge(DigitalAsset $keeper, DigitalAsset $duplicate, User $by, bool $confirmedCrossCustomer = false, ?string $note = null): AssetMerge
    {
        if (! $by->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages(['authorization' => 'Kopya web sitelerini yalnız Admin birleştirebilir.']);
        }
        $this->assertMergeable($keeper, $duplicate);
        $crossCustomer = $this->isCrossCustomer($keeper, $duplicate);
        if ($crossCustomer && ! $confirmedCrossCustomer) {
            throw ValidationException::withMessages([
                'confirmation' => sprintf(
                    'Bu iki kayıt farklı müşterilere ait (%s / %s). Birleştirmek bir yetki devridir; onaylayın.',
                    (string) $duplicate->brand?->customer?->name,
                    (string) $keeper->brand?->customer?->name,
                ),
            ]);
        }

        try {
            $merge = DB::transaction(fn (): AssetMerge => $this->runMerge($keeper, $duplicate, $by, $crossCustomer, $note));
        } catch (QueryException $exception) {
            Log::warning('ownership.website-merge-failed', ['keeper_id' => $keeper->id, 'duplicate_id' => $duplicate->id, 'table' => $this->currentTable, 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages([
                'merge' => sprintf('Birleştirme tamamlanamadı (%s tablosu); hiçbir şey değişmedi.', $this->currentTable !== '' ? $this->currentTable : 'bilinmeyen'),
            ]);
        }

        // Moved crawl/CMS/GSC rows only reach the page inventory (and the SEO plan) through a projection rebuild.
        try {
            RebuildWebsiteProjectionJob::dispatch(websiteAssetId: (int) $keeper->id, trigger: 'asset_merge');
        } catch (Throwable $exception) {
            report($exception); // the merge itself is committed; the next collection rebuilds the projection anyway
        }

        return $merge;
    }

    /** Same-host check shared by plan() and merge(). */
    public function assertMergeable(DigitalAsset $keeper, DigitalAsset $duplicate): void
    {
        $keeper->loadMissing('brand.customer');
        $duplicate->loadMissing('brand.customer');
        $error = match (true) {
            (int) $keeper->id === (int) $duplicate->id => 'Bir kayıt kendisiyle birleştirilemez.',
            $keeper->type !== 'website' || $duplicate->type !== 'website' => 'Yalnız web sitesi varlıkları birleştirilebilir.',
            $keeper->trashed() || $duplicate->trashed() => 'Silinmiş ya da daha önce birleştirilmiş bir kayıt birleştirilemez.',
            array_intersect($this->hosts($keeper), $this->hosts($duplicate)) === [] => 'Bu iki web sitesinin adresi aynı değil; yalnız aynı alan adındaki kayıtlar birleştirilir.',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['merge' => $error]);
        }
    }

    public function isCrossCustomer(DigitalAsset $keeper, DigitalAsset $duplicate): bool
    {
        $keeper->loadMissing('brand');
        $duplicate->loadMissing('brand');
        $from = $duplicate->brand?->customer_id;
        $to = $keeper->brand?->customer_id;

        return $from !== null && $to !== null && (int) $from !== (int) $to;
    }

    /** @return list<string> normalized hosts of the asset's URL and domain */
    public function hosts(DigitalAsset $asset): array
    {
        return array_values(array_unique(array_filter([
            BrandSetupMatcher::host((string) $asset->primary_url),
            BrandSetupMatcher::host((string) $asset->domain),
        ], fn (string $host): bool => $host !== '')));
    }

    private function runMerge(DigitalAsset $keeper, DigitalAsset $duplicate, User $by, bool $crossCustomer, ?string $note): AssetMerge
    {
        DigitalAsset::query()->whereKey([$keeper->id, $duplicate->id])->lockForUpdate()->get();
        $from = [
            'customer_id' => $duplicate->brand?->customer_id,
            'customer' => $duplicate->brand?->customer?->name,
            'brand_id' => $duplicate->brand_id,
            'brand' => $duplicate->brand?->name,
        ];
        $conflict = $crossCustomer && $keeper->brand !== null ? app(OwnershipGuard::class)->forAssetMove($duplicate, $keeper->brand) : null;

        // A brandless keeper takes the duplicate's brand, so the merged site keeps its owner.
        if ($keeper->brand_id === null && $duplicate->brand_id !== null) {
            $this->currentTable = 'digital_assets';
            $keeper->forceFill(['brand_id' => $duplicate->brand_id])->save();
            $keeper->load('brand.customer');
        }

        $stats = ['moved' => [], 'dropped' => []];
        $resourceIds = $this->mergeBindings($keeper, $duplicate, $by, $stats);
        $connectorSide = $this->mergeConnectors($keeper, $duplicate, $by, $stats);
        $overrides = $this->ownerOverrides($keeper, $duplicate);
        foreach ($this->schemaMap()['refs'] as $ref) {
            $this->moveRows($ref['table'], $ref['column'], (int) $duplicate->id, (int) $keeper->id, $overrides, 0, $stats, $connectorSide);
        }

        $mapping = [];
        if ($from['brand_id'] !== null && (int) $from['brand_id'] !== (int) $keeper->brand_id && $resourceIds !== []) {
            $this->currentTable = 'resource_automations';
            $mapping = $this->transfers->rescopeMappings($resourceIds, ! $crossCustomer, $keeper->brand_id !== null ? (int) $keeper->brand_id : null);
        }

        $transfer = null;
        if ($conflict !== null) {
            $this->currentTable = 'ownership_transfers';
            $transfer = OwnershipTransfer::query()->create([
                'subject_type' => OwnershipTransfer::SUBJECT_ASSET,
                'subject_id' => (int) $duplicate->id,
                'from_customer_id' => $conflict->currentCustomerId,
                'from_brand_id' => $conflict->currentBrandId,
                'from_asset_id' => (int) $duplicate->id,
                'to_customer_id' => $conflict->targetCustomerId,
                'to_brand_id' => $conflict->targetBrandId,
                'to_asset_id' => (int) $keeper->id,
                'transferred_by' => $by->id,
                'note' => $this->cleanNote($note) ?? sprintf('Kopya web sitesi #%d, #%d ile birleştirildi.', $duplicate->id, $keeper->id),
                'snapshot' => array_merge($conflict->snapshot(), [
                    'merge' => true,
                    'from_asset' => (string) $duplicate->name,
                    'to_asset' => (string) $keeper->name,
                ], $mapping !== [] ? ['mapping' => $mapping] : []),
            ]);
        }

        $this->currentTable = 'digital_assets';
        $duplicate->forceFill(['status' => DigitalAssetStatus::Archived, 'merged_into_asset_id' => (int) $keeper->id])->save();
        $duplicate->delete();

        $this->currentTable = 'asset_merges';
        $merge = AssetMerge::query()->create([
            'keeper_id' => (int) $keeper->id,
            'duplicate_id' => (int) $duplicate->id,
            'host' => $this->hosts($keeper)[0] ?? null,
            'cross_customer' => $crossCustomer,
            'ownership_transfer_id' => $transfer?->id,
            'moved' => $stats['moved'],
            'dropped' => $stats['dropped'],
            'snapshot' => [
                'keeper' => (string) $keeper->name,
                'duplicate' => (string) $duplicate->name,
                'from_customer' => $from['customer'],
                'from_brand' => $from['brand'],
                'to_customer' => $keeper->brand?->customer?->name,
                'to_brand' => $keeper->brand?->name,
            ] + ($mapping !== [] ? ['mapping' => $mapping] : []),
            'note' => trim(sprintf('merged into #%d. %s', $keeper->id, (string) $this->cleanNote($note))),
            'merged_by' => $by->id,
        ]);
        $this->currentTable = '';

        Log::info('ownership.website-merged', [
            'merge_id' => $merge->id,
            'keeper_id' => $keeper->id,
            'duplicate_id' => $duplicate->id,
            'cross_customer' => $crossCustomer,
            'moved' => array_sum($stats['moved']),
            'dropped' => array_sum($stats['dropped']),
            'user_id' => $by->id,
        ]);

        return $merge;
    }

    /**
     * Moves the rows of one table from one parent id to another, resolving unique-key collisions first.
     *
     * @param  array<string, int>  $overrides  owner columns (brand_id / customer_id) of the keeper
     * @param  array{moved: array<string, int>, dropped: array<string, int>}  $stats
     */
    private function moveRows(string $table, string $column, int $from, int $to, array $overrides, int $depth, array &$stats, string $connectorSide): void
    {
        $this->currentTable = $table;
        $set = $this->setFor($table, $column, $to, $overrides);
        foreach ($this->collisionIndexes($table, $column) as $unique) {
            $this->resolveCollisions($table, $column, $unique, $from, $set, $depth, $stats, $connectorSide);
        }
        $this->currentTable = $table;

        $moved = 0;
        if ($this->hasId($table)) {
            while (($ids = DB::table($table)->where($column, $from)->orderBy('id')->limit(self::CHUNK)->pluck('id'))->isNotEmpty()) {
                $moved += DB::table($table)->whereIn('id', $ids->all())->update($set);
            }
        } else {
            $moved = DB::table($table)->where($column, $from)->update($set);
        }
        if ($moved > 0) {
            $stats['moved'][$table] = ($stats['moved'][$table] ?? 0) + $moved;
        }
    }

    /**
     * @param  list<string>  $unique
     * @param  array<string, int>  $set
     * @param  array{moved: array<string, int>, dropped: array<string, int>}  $stats
     */
    private function resolveCollisions(string $table, string $column, array $unique, int $from, array $set, int $depth, array &$stats, string $connectorSide): void
    {
        $rule = $this->ruleFor($table);
        $children = $depth < self::MAX_DEPTH ? ($this->schemaMap()['children'][$table] ?? []) : [];
        $dropped = 0;

        if (! $this->hasId($table)) {
            // No id: keeper's row stays; the duplicate's colliding rows go, matched on the unique key itself.
            foreach ($this->collisionQuery($table, $column, $unique, $from, $set)->get(array_map(fn (string $c): string => 'd.'.$c, $unique)) as $row) {
                $delete = DB::table($table);
                foreach ($unique as $c) {
                    $delete->where($c, ((array) $row)[$c]);
                }
                $dropped += $delete->delete();
            }
        } elseif ($rule === 'keeper' && $children === []) {
            while (($ids = $this->collisionQuery($table, $column, $unique, $from, $set)->orderBy('d.id')->limit(self::CHUNK)->pluck('d.id'))->isNotEmpty()) {
                $dropped += DB::table($table)->whereIn('id', $ids->all())->delete();
            }
        } else {
            $time = $this->tableMeta($table)['time'];
            $select = ['d.id as dup_id', 'k.id as keep_id'];
            if ($time !== null) {
                $select[] = 'd.'.$time.' as dup_time';
                $select[] = 'k.'.$time.' as keep_time';
            }
            while (($rows = $this->collisionQuery($table, $column, $unique, $from, $set)->orderBy('d.id')->limit(self::CHUNK)->get($select))->isNotEmpty()) {
                foreach ($rows as $row) {
                    $duplicateWins = match ($rule) {
                        'connector' => $connectorSide === 'duplicate',
                        'newer' => $this->isNewer($row->dup_time ?? null, $row->keep_time ?? null, (int) $row->dup_id, (int) $row->keep_id),
                        default => false,
                    };
                    [$loser, $winner] = $duplicateWins ? [(int) $row->keep_id, (int) $row->dup_id] : [(int) $row->dup_id, (int) $row->keep_id];
                    foreach ($children as $child) {
                        $this->moveRows($child['table'], $child['column'], $loser, $winner, [], $depth + 1, $stats, $connectorSide);
                    }
                    $this->currentTable = $table;
                    $dropped += DB::table($table)->where('id', $loser)->delete();
                }
            }
        }

        if ($dropped > 0) {
            $stats['dropped'][$table] = ($stats['dropped'][$table] ?? 0) + $dropped;
        }
    }

    /**
     * Duplicate rows (d) that would break the unique key once moved: a keeper row (k) with the same key values.
     *
     * @param  list<string>  $unique
     * @param  array<string, int>  $set
     */
    private function collisionQuery(string $table, string $column, array $unique, int $from, array $set): QueryBuilder
    {
        return DB::table($table.' as d')
            ->join($table.' as k', function (JoinClause $join) use ($column, $unique, $set): void {
                $join->where('k.'.$column, '=', $set[$column]);
                foreach ($unique as $c) {
                    if ($c === $column) {
                        continue;
                    }
                    if (array_key_exists($c, $set)) {
                        $join->where('k.'.$c, '=', $set[$c]);
                    } else {
                        $join->on('k.'.$c, '=', 'd.'.$c);
                    }
                }
            })
            ->where('d.'.$column, $from);
    }

    /**
     * One binding per capability on the keeper. Returns the resources whose active binding moved to the keeper.
     *
     * @param  array{moved: array<string, int>, dropped: array<string, int>}  $stats
     * @return list<int>
     */
    private function mergeBindings(DigitalAsset $keeper, DigitalAsset $duplicate, User $by, array &$stats): array
    {
        $this->currentTable = 'core_asset_bindings';
        $resourceIds = [];
        foreach ($this->bindingDecisions($keeper, $duplicate) as $decision) {
            /** @var CoreAssetBinding $binding */
            $binding = $decision['binding'];
            $active = $binding->status === CoreAssetBinding::STATUS_ACTIVE;
            if ($decision['action'] === 'move') {
                $binding->forceFill(['digital_asset_id' => $keeper->id])->save();
            } elseif ($decision['action'] === 'replace') {
                // The keeper's inactive binding goes to the archived duplicate (history); the active one takes its place.
                /** @var CoreAssetBinding $old */
                $old = $decision['keeper_binding'];
                $capability = (string) $binding->capability;
                $binding->forceFill(['capability' => $capability.'#merge-'.$binding->id])->save();
                $old->forceFill([
                    'digital_asset_id' => $duplicate->id,
                    'configuration' => array_merge(is_array($old->configuration) ? $old->configuration : [], ['replaced_on_merge_by_binding_id' => (int) $binding->id]),
                ])->save();
                $binding->forceFill(['digital_asset_id' => $keeper->id, 'capability' => $capability])->save();
            } else {
                if ($active) {
                    $binding->forceFill([
                        'status' => CoreAssetBinding::STATUS_DISABLED,
                        'configuration' => array_merge(is_array($binding->configuration) ? $binding->configuration : [], [
                            'closed_by_user_id' => $by->id,
                            'closed_at' => now()->toIso8601String(),
                            'closed_reason' => self::MERGED_REASON,
                            'merged_into_asset_id' => (int) $keeper->id,
                        ]),
                    ])->save();
                }
                $stats['dropped']['core_asset_bindings'] = ($stats['dropped']['core_asset_bindings'] ?? 0) + 1;

                continue;
            }
            $stats['moved']['core_asset_bindings'] = ($stats['moved']['core_asset_bindings'] ?? 0) + 1;
            if ($active) {
                $resourceIds[] = (int) $binding->external_resource_id;
            }
        }

        return array_values(array_unique($resourceIds));
    }

    /**
     * move = no keeper binding for the capability; replace = duplicate active, keeper's inactive; disable = the
     * keeper's binding wins (the duplicate's stays on the archived duplicate, disabled).
     *
     * @return list<array{binding: CoreAssetBinding, action: string, keeper_binding: ?CoreAssetBinding}>
     */
    private function bindingDecisions(DigitalAsset $keeper, DigitalAsset $duplicate): array
    {
        $keeperBindings = CoreAssetBinding::query()->where('digital_asset_id', $keeper->id)->get()->keyBy('capability');
        $decisions = [];
        foreach (CoreAssetBinding::query()->where('digital_asset_id', $duplicate->id)->orderBy('id')->get() as $binding) {
            $existing = $keeperBindings->get($binding->capability);
            $action = match (true) {
                ! $existing instanceof CoreAssetBinding => 'move',
                $existing->status !== CoreAssetBinding::STATUS_ACTIVE && $binding->status === CoreAssetBinding::STATUS_ACTIVE => 'replace',
                default => 'disable',
            };
            if ($action !== 'disable') {
                $keeperBindings->put($binding->capability, $binding);
            }
            $decisions[] = ['binding' => $binding, 'action' => $action, 'keeper_binding' => $existing];
        }

        return $decisions;
    }

    /**
     * One connector per type on the keeper. Returns which side's WordPress connector won ("keeper" / "duplicate").
     *
     * @param  array{moved: array<string, int>, dropped: array<string, int>}  $stats
     */
    private function mergeConnectors(DigitalAsset $keeper, DigitalAsset $duplicate, User $by, array &$stats): string
    {
        $this->currentTable = 'core_connections';
        $side = 'keeper';
        foreach ($this->connectorDecisions($keeper, $duplicate) as $decision) {
            /** @var CoreConnection $winner */
            $winner = $decision['winner_connection'];
            foreach ($decision['losers'] as $loser) {
                /** @var CoreConnection $loser */
                $loser->forceFill([
                    'digital_asset_id' => $duplicate->id,
                    'enabled' => false,
                    'config' => array_merge(is_array($loser->config) ? $loser->config : [], [
                        'disabled_reason' => self::MERGED_REASON,
                        'merged_into_asset_id' => (int) $keeper->id,
                        'merged_by_user_id' => $by->id,
                        'merged_at' => now()->toIso8601String(),
                    ]),
                ])->save();
                $stats['dropped']['core_connections'] = ($stats['dropped']['core_connections'] ?? 0) + 1;
            }
            if ((int) $winner->digital_asset_id !== (int) $keeper->id) {
                $winner->forceFill(['digital_asset_id' => $keeper->id])->save();
                $stats['moved']['core_connections'] = ($stats['moved']['core_connections'] ?? 0) + 1;
                if ($decision['type'] === WordPressConnectorPairingService::CONNECTION_TYPE) {
                    $side = 'duplicate';
                }
            }
        }

        return $side;
    }

    /**
     * Per connector type: the paired + enabled one wins, then enabled, then the keeper's, then the latest success.
     *
     * @return list<array{type: string, winner: string, winner_connection: CoreConnection, losers: list<CoreConnection>}>
     */
    private function connectorDecisions(DigitalAsset $keeper, DigitalAsset $duplicate): array
    {
        $all = CoreConnection::query()->whereIn('digital_asset_id', [$keeper->id, $duplicate->id])->orderBy('id')->get();
        $decisions = [];
        foreach ($all->groupBy('type') as $type => $connections) {
            if ($connections->every(fn (CoreConnection $c): bool => (int) $c->digital_asset_id === (int) $keeper->id)) {
                continue;
            }
            $sorted = $connections->sort(function (CoreConnection $a, CoreConnection $b) use ($keeper): int {
                $score = fn (CoreConnection $c): array => [
                    $this->isPaired($c) ? 1 : 0,
                    $c->enabled ? 1 : 0,
                    (int) $c->digital_asset_id === (int) $keeper->id ? 1 : 0,
                    $c->last_success_at?->getTimestamp() ?? 0,
                ];

                return $score($b) <=> $score($a);
            })->values();
            $winner = $sorted->first();
            $decisions[] = [
                'type' => (string) $type,
                'winner' => (int) $winner->digital_asset_id === (int) $keeper->id ? 'keeper' : 'duplicate',
                'winner_connection' => $winner,
                'losers' => $sorted->slice(1)->filter(fn (CoreConnection $c): bool => (int) $c->digital_asset_id === (int) $keeper->id || $c->enabled)->values()->all(),
            ];
        }

        return $decisions;
    }

    private function isPaired(CoreConnection $connection): bool
    {
        return (bool) $connection->enabled && data_get($connection->config, 'pairing_state') === WordPressConnectorPairingService::PAIRED;
    }

    /** @return array<string, int> brand / customer values moved rows take (keeper's owner) */
    private function ownerOverrides(DigitalAsset $keeper, DigitalAsset $duplicate): array
    {
        $keeper->loadMissing('brand');
        if ($keeper->brand_id === null || (int) $keeper->brand_id === (int) $duplicate->brand_id) {
            return [];
        }

        return array_filter([
            'brand' => (int) $keeper->brand_id,
            'customer' => $keeper->brand?->customer_id !== null ? (int) $keeper->brand->customer_id : null,
        ], fn (?int $value): bool => $value !== null);
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function setFor(string $table, string $column, int $to, array $overrides): array
    {
        $meta = $this->tableMeta($table);
        $set = [$column => $to];
        foreach (['brand', 'customer'] as $owner) {
            $ownerColumn = $meta[$owner];
            if ($ownerColumn !== null && $ownerColumn !== $column && isset($overrides[$owner])) {
                $set[$ownerColumn] = $overrides[$owner];
            }
        }

        return $set;
    }

    /** @return list<list<string>> unique keys (incl. composite primary keys) that contain the column */
    private function collisionIndexes(string $table, string $column): array
    {
        return array_values(array_filter($this->tableMeta($table)['uniques'], fn (array $unique): bool => in_array($column, $unique, true)));
    }

    private function ruleFor(string $table): string
    {
        return match (true) {
            in_array($table, self::FOLLOWS_CONNECTOR, true) => 'connector',
            in_array($table, self::NEWER_WINS, true) => 'newer',
            default => 'keeper',
        };
    }

    private function hasId(string $table): bool
    {
        return $this->tableMeta($table)['has_id'];
    }

    private function isNewer(mixed $duplicateTime, mixed $keeperTime, int $duplicateId, int $keeperId): bool
    {
        $parse = fn (mixed $value): ?int => $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value)->getTimestamp();
        $dup = $parse($duplicateTime);
        $keep = $parse($keeperTime);
        if ($dup !== null && $keep !== null && $dup !== $keep) {
            return $dup > $keep;
        }
        if ($dup !== null && $keep === null) {
            return true;
        }

        return $keep === null && $dup === null && $duplicateId > $keeperId;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    private function countFor(string $table, string $column, int $id): int
    {
        if (! ($this->readable[$table] ??= Schema::hasTable($table) || Schema::hasView($table))) {
            return 0;
        }

        return DB::table($table)->where($column, $id)->count();
    }

    /**
     * @return array{columns: list<string>, has_id: bool, uniques: list<list<string>>, time: ?string, brand: ?string, customer: ?string}
     */
    private function tableMeta(string $table): array
    {
        if (isset($this->meta[$table])) {
            return $this->meta[$table];
        }
        $columns = Schema::getColumnListing($table);
        $foreign = collect(Schema::getForeignKeys($table))->filter(fn (array $fk): bool => count($fk['columns']) === 1);
        $ownerColumn = fn (string $target): ?string => $foreign->first(fn (array $fk): bool => $fk['foreign_table'] === $target)['columns'][0] ?? null;

        return $this->meta[$table] = [
            'columns' => $columns,
            'has_id' => in_array('id', $columns, true),
            'uniques' => collect(Schema::getIndexes($table))
                ->filter(fn (array $index): bool => (bool) ($index['unique'] ?? false) || (bool) ($index['primary'] ?? false))
                ->map(fn (array $index): array => array_values($index['columns']))
                ->reject(fn (array $cols): bool => $cols === ['id'])
                ->values()->all(),
            'time' => in_array('updated_at', $columns, true) ? 'updated_at' : (in_array('created_at', $columns, true) ? 'created_at' : null),
            'brand' => $ownerColumn('brands'),
            'customer' => $ownerColumn('customers'),
        ];
    }

    /**
     * Every table and column that points at a digital asset, and which tables point at each table (for re-pointing
     * rows of a dropped row).
     *
     * @return array{tables: list<string>, refs: list<array{table: string, column: string}>, children: array<string, list<array{table: string, column: string}>>}
     */
    private function schemaMap(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $current = Schema::getCurrentSchemaName();
        $tables = collect(Schema::getTables())
            ->filter(fn (array $t): bool => $current === null || ($t['schema'] ?? $current) === $current)
            ->pluck('name')->map(fn (mixed $name): string => (string) $name)
            ->reject(fn (string $name): bool => in_array($name, self::SKIP_TABLES, true))
            ->sort()->values();

        $refs = [];
        $children = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $assetColumns = array_values(array_intersect(self::ASSET_COLUMNS, $columns));
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    continue;
                }
                $column = (string) $fk['columns'][0];
                if ($fk['foreign_table'] === 'digital_assets') {
                    $assetColumns[] = $column;
                } elseif (! in_array($fk['foreign_table'], self::SKIP_TABLES, true)) {
                    $children[(string) $fk['foreign_table']][] = ['table' => $table, 'column' => $column];
                }
            }
            if (in_array($table, self::CUSTOM_TABLES, true)) {
                continue;
            }
            foreach (array_values(array_unique($assetColumns)) as $column) {
                $refs[] = ['table' => $table, 'column' => $column];
            }
        }

        return $this->schema = ['tables' => $tables->all(), 'refs' => $refs, 'children' => $children];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recentMerges(int $limit = 20): Collection
    {
        return AssetMerge::query()->with('mergedBy')->latest('id')->limit($limit)->get()->map(fn (AssetMerge $merge): array => [
            'id' => (int) $merge->id,
            'keeper_id' => (int) $merge->keeper_id,
            'duplicate_id' => (int) $merge->duplicate_id,
            'host' => $merge->host,
            'cross_customer' => (bool) $merge->cross_customer,
            'moved' => $merge->movedTotal(),
            'dropped' => $merge->droppedTotal(),
            'by' => $merge->mergedBy?->name,
            'at' => $merge->created_at?->format('d.m.Y H:i'),
            'keeper' => (string) ($merge->snapshot['keeper'] ?? ''),
            'duplicate' => (string) ($merge->snapshot['duplicate'] ?? ''),
        ]);
    }
}
