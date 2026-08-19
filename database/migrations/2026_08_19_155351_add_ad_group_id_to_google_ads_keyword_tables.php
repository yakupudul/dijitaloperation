<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->evolveKeywordSnapshot();
        $this->evolveKeywordDaily();
    }

    public function down(): void
    {
        // Additive grain correction; do not collapse ad-group identity on rollback.
    }

    private function evolveKeywordSnapshot(): void
    {
        if (! Schema::hasTable('google_ads_keyword_snapshot')) {
            return;
        }

        if (Schema::hasColumn('google_ads_keyword_snapshot', 'ad_group_id')) {
            return;
        }

        Schema::table('google_ads_keyword_snapshot', function (Blueprint $table): void {
            $table->text('ad_group_id')->default('');
        });

        $this->backfillAdGroupId('google_ads_keyword_snapshot');
        $this->replaceUnique(
            'google_ads_keyword_snapshot',
            'google_ads_keyword_snapshot_nk_unique',
            ['digital_asset_id', 'customer_id', 'ad_group_id', 'criterion_id'],
        );
    }

    private function evolveKeywordDaily(): void
    {
        if (! Schema::hasTable('google_ads_keyword_daily')) {
            return;
        }

        if (Schema::hasColumn('google_ads_keyword_daily', 'ad_group_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE google_ads_keyword_daily ADD COLUMN IF NOT EXISTS ad_group_id text NOT NULL DEFAULT ''");
        } else {
            Schema::table('google_ads_keyword_daily', function (Blueprint $table): void {
                $table->text('ad_group_id')->default('');
            });
        }

        $this->backfillAdGroupId('google_ads_keyword_daily');
        $this->replaceUnique(
            'google_ads_keyword_daily',
            'google_ads_keyword_daily_nk_unique',
            ['digital_asset_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id'],
        );
    }

    private function backfillAdGroupId(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("UPDATE {$table} SET ad_group_id = metadata->>'ad_group_id' WHERE (ad_group_id IS NULL OR ad_group_id = '') AND COALESCE(metadata->>'ad_group_id', '') <> ''");

            return;
        }

        foreach (DB::table($table)->select(['id', 'metadata'])->cursor() as $row) {
            $meta = $row->metadata;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (! is_array($meta)) {
                continue;
            }
            $adGroupId = (string) ($meta['ad_group_id'] ?? '');
            if ($adGroupId === '') {
                continue;
            }
            DB::table($table)->where('id', $row->id)->update(['ad_group_id' => $adGroupId]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceUnique(string $table, string $newName, array $columns): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $constraints = DB::select(
                "SELECT c.conname, pg_get_constraintdef(c.oid) AS definition
                 FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 WHERE t.relname = ?
                   AND c.contype = 'u'",
                [$table],
            );
            foreach ($constraints as $constraint) {
                $definition = strtolower((string) $constraint->definition);
                if (! str_contains($definition, 'criterion_id') || str_contains($definition, 'ad_group_id')) {
                    continue;
                }
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS '.$constraint->conname);
            }

            $quoted = implode(', ', array_map(fn (string $col): string => '"'.$col.'"', $columns));
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$newName.' UNIQUE ('.$quoted.')');

            return;
        }

        $indexes = DB::select("PRAGMA index_list('{$table}')");
        foreach ($indexes as $index) {
            $name = (string) ($index->name ?? '');
            if ($name === '' || (int) ($index->unique ?? 0) !== 1) {
                continue;
            }
            $cols = collect(DB::select("PRAGMA index_info('{$name}')"))->pluck('name')->all();
            if (! in_array('criterion_id', $cols, true) || in_array('ad_group_id', $cols, true)) {
                continue;
            }
            DB::statement('DROP INDEX IF EXISTS "'.$name.'"');
        }

        $remaining = DB::select("PRAGMA index_list('{$table}')");
        $already = collect($remaining)->contains(fn ($index): bool => (string) ($index->name ?? '') === $newName);
        if ($already) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $newName): void {
            $blueprint->unique($columns, $newName);
        });
    }
};
