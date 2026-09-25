<?php

namespace App\Services\Portfolio;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Hard-deletes customers or brands together with every row scoped to them (brands, digital assets and all collected
 * data). Admin-only, destructive, not reversible — the caller confirms first.
 *
 * The delete is schema-driven, not a hand-maintained table list, so it does not rot as the schema grows:
 *  1. A recursive walk of the live foreign-key graph deletes the entire subtree under the customer / brand — every
 *     child, grandchild and so on, whatever the on-delete rule — ending with the root row. Nullable back-references
 *     that would form a cycle are set null instead of recursed into.
 *  2. Tables that carry a customer_id / brand_id / digital_asset_id column but no foreign key on it (the analytics
 *     data lake) are then cleared by that column, since the FK walk cannot reach them.
 * Everything runs in one transaction per entity, so a delete that cannot complete rolls back and the entity is
 * reported as skipped rather than half-deleted. Works on both PostgreSQL and SQLite.
 */
final class PortfolioDeletionService
{
    private const array ROOTS = ['digital_assets', 'brands', 'customers'];

    private const array KEYS = ['digital_asset_id', 'brand_id', 'customer_id'];

    /** @var array<string, list<array{table: string, column: string, foreign: string}>>|null foreign table => referrers */
    private ?array $referrers = null;

    /** @var array<string, list<string>>|null nullable columns per table */
    private ?array $nullable = null;

    /** @var array<string, list<string>>|null lake tables => key columns with no FK */
    private ?array $lake = null;

    public function __construct(private readonly SecurityAuditRecorder $audit) {}

    /**
     * @param  list<int>  $customerIds
     * @return array{deleted: int, skipped: int}
     */
    public function deleteCustomers(array $customerIds, ?User $actor = null): array
    {
        $names = DB::table('customers')->whereIn('id', $customerIds)->pluck('name', 'id');
        $deleted = 0;
        $skipped = 0;
        foreach ($customerIds as $id) {
            if (! $names->has($id)) {
                continue;
            }
            $this->purge('customers', (int) $id) ? $deleted++ : $skipped++;
        }
        $this->log($actor, 'customer', $names->all());

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $brandIds
     * @return array{deleted: int, skipped: int}
     */
    public function deleteBrands(array $brandIds, ?User $actor = null): array
    {
        $names = DB::table('brands')->whereIn('id', $brandIds)->pluck('name', 'id');
        $deleted = 0;
        $skipped = 0;
        foreach ($brandIds as $id) {
            if (! $names->has($id)) {
                continue;
            }
            $this->purge('brands', (int) $id) ? $deleted++ : $skipped++;
        }
        $this->log($actor, 'brand', $names->all());

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /** Delete one root row (a customer or a brand) and everything under it, atomically. */
    private function purge(string $root, int $id): bool
    {
        // Capture the scoped id sets before anything is deleted, so the FK-less lake can be cleared by them afterwards.
        $brandIds = $root === 'brands' ? [$id] : DB::table('brands')->where('customer_id', $id)->pluck('id')->map('intval')->all();
        $assetIds = $brandIds === [] ? [] : DB::table('digital_assets')->whereIn('brand_id', $brandIds)->pluck('id')->map('intval')->all();
        $lakeIds = array_filter([
            'digital_asset_id' => $assetIds,
            'brand_id' => $brandIds,
            'customer_id' => $root === 'customers' ? [$id] : [],
        ], static fn (array $ids): bool => $ids !== []);

        try {
            DB::transaction(function () use ($root, $id, $lakeIds): void {
                $this->deleteSubtree($root, [$id], []);
                foreach ($this->lakeTables() as $table => $keys) {
                    foreach ($keys as $key) {
                        if (isset($lakeIds[$key])) {
                            DB::table($table)->whereIn($key, $lakeIds[$key])->delete();
                        }
                    }
                }
            });

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Delete the given rows of $table and, first, every row that references them (recursively).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $stack  tables currently being deleted, to break reference cycles
     */
    private function deleteSubtree(string $table, array $ids, array $stack): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return;
        }
        foreach ($this->referrersOf($table) as $ref) {
            $parentValues = DB::table($table)->whereIn('id', $ids)->pluck($ref['foreign'])->all();
            $parentValues = array_values(array_filter($parentValues, static fn ($v): bool => $v !== null));
            if ($parentValues === []) {
                continue;
            }
            if (in_array($ref['table'], $stack, true) || $ref['table'] === $table) {
                // A cycle (e.g. a "current revision" back-reference): break it by nulling the column instead of recursing.
                if (in_array($ref['column'], $this->nullableColumns($ref['table']), true)) {
                    DB::table($ref['table'])->whereIn($ref['column'], $parentValues)->update([$ref['column'] => null]);
                }

                continue;
            }
            if (! $this->hasIdColumn($ref['table'])) {
                // A keyless pivot cannot itself be an FK target, so nothing references it: delete its rows directly.
                DB::table($ref['table'])->whereIn($ref['column'], $parentValues)->delete();

                continue;
            }
            $childIds = DB::table($ref['table'])->whereIn($ref['column'], $parentValues)->pluck('id')->map('intval')->all();
            $this->deleteSubtree($ref['table'], $childIds, [...$stack, $table]);
        }
        DB::table($table)->whereIn('id', $ids)->delete();
    }

    /**
     * @return list<array{table: string, column: string, foreign: string}>
     */
    private function referrersOf(string $table): array
    {
        if ($this->referrers === null) {
            $this->buildForeignKeyGraph();
        }

        return $this->referrers[$table] ?? [];
    }

    private function buildForeignKeyGraph(): void
    {
        $this->referrers = [];
        $this->lake = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? '') : (string) $table;
            if ($name === '') {
                continue;
            }
            $fkColumns = [];
            foreach (Schema::getForeignKeys($name) as $fk) {
                $column = $fk['columns'][0] ?? null;
                $foreignTable = $fk['foreign_table'] ?? null;
                $foreignColumn = $fk['foreign_columns'][0] ?? 'id';
                if ($column === null || $foreignTable === null) {
                    continue;
                }
                $fkColumns[] = $column;
                $this->referrers[$foreignTable][] = ['table' => $name, 'column' => $column, 'foreign' => $foreignColumn];
            }
            // Tables that carry a scope key with NO foreign key on it (the analytics data lake) are cleared by key.
            if (! in_array($name, self::ROOTS, true)) {
                $columns = Schema::getColumnListing($name);
                $lakeKeys = array_values(array_diff(array_intersect(self::KEYS, $columns), $fkColumns));
                if ($lakeKeys !== []) {
                    $this->lake[$name] = $lakeKeys;
                }
            }
        }
    }

    /** @return array<string, list<string>> */
    private function lakeTables(): array
    {
        if ($this->lake === null) {
            $this->buildForeignKeyGraph();
        }

        return $this->lake ?? [];
    }

    private function hasIdColumn(string $table): bool
    {
        return in_array('id', Schema::getColumnListing($table), true);
    }

    /** @return list<string> */
    private function nullableColumns(string $table): array
    {
        if (! isset($this->nullable[$table])) {
            $this->nullable[$table] = array_values(array_map(
                static fn (array $c): string => (string) $c['name'],
                array_filter(Schema::getColumns($table), static fn (array $c): bool => (bool) ($c['nullable'] ?? false)),
            ));
        }

        return $this->nullable[$table];
    }

    /** @param  array<int, string>  $names */
    private function log(?User $actor, string $type, array $names): void
    {
        if ($names === []) {
            return;
        }
        try {
            // customer_id / brand_id are left null: the referenced rows are gone, so the ids live in metadata instead.
            $this->audit->record(SecurityAuditEventKind::SecuritySettingChanged, $actor, null, null, null, null,
                $type === 'customer' ? 'Müşteri(ler) kalıcı olarak silindi' : 'Marka(lar) kalıcı olarak silindi',
                ['type' => $type, 'count' => count($names), 'deleted' => array_slice($names, 0, 200, true)]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
