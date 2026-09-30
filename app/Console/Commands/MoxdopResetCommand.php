<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Horizon;
use Throwable;

/**
 * moxdop:reset — MoxDOP v2 data reset (Faz 0): empties the data collected from the accounts and everything derived
 * from it; the portfolio stays.
 *
 * Dry-run by default: one row per table with its row count and whether it will be TRUNCATED or KEPT. `--apply` asks
 * for the application name as confirmation. Kept: users / roles / permissions, integrations + connections +
 * credentials + discovered resources, customers / brands / assets / account bindings and the operator's brand
 * settings (services, service areas, goals), settings, the sector / service catalog, standards, AI provider settings,
 * operator inputs (backlinks, lead marks, budget plans), audit logs, migrations. Everything else (collected facts,
 * collection runs, derived pages / queries / suggestions, retired modules) is emptied; the cache and the Horizon
 * queues are cleared afterwards and collection starts again from scratch for the bound accounts.
 */
final class MoxdopResetCommand extends Command
{
    protected $signature = 'moxdop:reset {--apply : Empty the tables (otherwise dry-run)}';

    protected $description = 'MoxDOP v2 reset: empty the collected account data and what was derived from it; keep users, integrations, customers, brands, assets, settings, catalog and standards.';

    /** Exact table names that survive the reset. */
    public const array KEEP = [
        // framework
        'migrations', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens',
        // users, roles, permissions
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'notification_preferences',
        // integrations, connections, credentials, discovered resources, OAuth / discovery attempts
        'core_integrations', 'core_integration_credentials', 'core_integration_discovery_contexts',
        'core_connections', 'core_connection_credentials', 'core_external_resources',
        'google_oauth_authorization_attempts', 'google_integration_discovery_attempts',
        'meta_oauth_authorization_attempts', 'meta_integration_discovery_attempts',
        // settings
        'agency_settings', 'module_registries', 'ai_route_steps',
        // sector / service catalog, sector packs, compliance rule catalog
        'service_categories', 'service_catalog_items', 'service_catalog_names', 'service_matching_keywords',
        'sector_product_brands', 'sector_pack_settings', 'compliance_rules',
        // standards
        'website_standard_settings',
        // v2 configuration tables and the backup log
        'prompt_versions', 'filter_terms', 'system_backups',
        // portfolio: customers, brands, assets, account bindings, the WordPress Connector delivery settings
        'customers', 'customer_user', 'customer_contacts', 'customer_service_scopes', 'customer_service_scope_brands',
        'customer_service_scope_inclusions', 'customer_service_scope_exclusions',
        'brands', 'brand_user', 'brand_offerings', 'brand_offering_names', 'brand_service_areas', 'brand_service_category',
        'brand_goals', 'brand_goal_offering', 'digital_assets', 'core_asset_bindings', 'website_connector_delivery',
        'service_definitions',
        // operator inputs and audit logs
        'backlinks', 'backlink_sources', 'meta_leads', 'google_ads_budget_plans', 'google_ads_conversion_business_mappings',
        'ownership_transfers', 'asset_merges', 'security_audit_events', 'operator_files',
    ];

    public function handle(): int
    {
        $tables = collect(Schema::getTableListing())->map(fn (string $name): string => str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name)
            ->filter(fn (string $name): bool => ! str_starts_with($name, 'sqlite_'))->sort()->values();
        $rows = $tables->map(fn (string $table): array => [
            'table' => $table,
            'rows' => $this->count($table),
            'action' => $this->kept($table) ? 'KEEP' : 'TRUNCATE',
        ]);
        $this->table(['Tablo', 'Satır', 'İşlem'], $rows->map(fn (array $r): array => [$r['table'], number_format($r['rows'], 0, ',', '.'), $r['action']])->all());
        $truncate = $rows->where('action', 'TRUNCATE')->pluck('table')->values();
        $this->line(sprintf('%d tablo boşaltılacak, %d tablo korunacak.', $truncate->count(), $rows->count() - $truncate->count()));

        $conflicts = $this->keptReferencingEmptied($truncate->all());
        if ($conflicts !== []) {
            $this->error('Korunan tablolar boşaltılacak tablolara bağlı; önce KEEP listesi düzeltilmeli: '.implode(', ', $conflicts));

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Deneme çalıştırması; hiçbir şey silinmedi. Uygulamak için --apply.');

            return self::SUCCESS;
        }
        $expected = (string) config('app.name');
        $typed = (string) $this->ask('Onaylamak için uygulama adını yazın ('.$expected.')');
        if (trim($typed) !== $expected) {
            $this->error('Onay eşleşmedi; iptal edildi.');

            return self::FAILURE;
        }

        $this->truncate($truncate->all());
        $this->clearCacheAndQueues();
        $this->info(sprintf('Sıfırlandı: %d tablo boşaltıldı, önbellek ve kuyruklar temizlendi.', $truncate->count()));

        return self::SUCCESS;
    }

    public static function kept(string $table): bool
    {
        return in_array($table, self::KEEP, true);
    }

    private function count(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * PostgreSQL foreign keys from a kept table to an emptied one (TRUNCATE would fail on them).
     *
     * @param  list<string>  $truncate
     * @return list<string> "kept → emptied"
     */
    private function keptReferencingEmptied(array $truncate): array
    {
        if (DB::getDriverName() !== 'pgsql' || $truncate === []) {
            return [];
        }
        $emptied = array_flip($truncate);
        $pairs = DB::select("select distinct c.conrelid::regclass::text as child, c.confrelid::regclass::text as parent from pg_constraint c where c.contype = 'f'");
        $conflicts = [];
        foreach ($pairs as $pair) {
            $child = trim((string) $pair->child, '"');
            $parent = trim((string) $pair->parent, '"');
            if (self::kept($child) && isset($emptied[$parent])) {
                $conflicts[] = $child.' → '.$parent;
            }
        }

        return $conflicts;
    }

    /** @param  list<string>  $tables */
    private function truncate(array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // One statement: references between the emptied tables are fine, a kept table referencing an emptied one is an error we want to see.
            DB::statement('truncate table '.implode(', ', array_map(fn (string $t): string => '"'.$t.'"', $tables)).' restart identity');

            return;
        }
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
            if ($driver === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
                DB::table('sqlite_sequence')->whereIn('name', $tables)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function clearCacheAndQueues(): void
    {
        try {
            Cache::flush();
        } catch (Throwable $exception) {
            $this->warn('Önbellek temizlenemedi: '.$exception->getMessage());
        }
        if ((string) config('queue.default') !== 'redis' || ! class_exists(Horizon::class)) {
            return;
        }
        $queues = array_values(array_unique(['default', (string) config('queue.heavy_queue', 'heavy'), 'collection']));
        foreach ($queues as $queue) {
            try {
                Artisan::call('horizon:clear', ['--queue' => $queue, '--force' => true]);
            } catch (Throwable $exception) {
                $this->warn('Kuyruk temizlenemedi ('.$queue.'): '.$exception->getMessage());
            }
        }
    }
}
