<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_automations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('external_resource_id')->unique()->constrained('core_external_resources')->cascadeOnDelete();
            $t->boolean('collection_enabled')->default(true);
            $t->unsignedSmallInteger('interval_days')->default(1);
            $t->string('collection_status', 32)->default('waiting');
            $t->timestampTz('next_collection_at')->nullable()->index();
            $t->timestampTz('collection_queued_at')->nullable();
            $t->unsignedBigInteger('collection_run_id')->nullable();
            $t->timestampTz('last_collection_success_at')->nullable();
            $t->date('data_through')->nullable();
            $t->string('collection_error', 120)->nullable();
            $t->unsignedSmallInteger('collection_failures')->default(0);
            $t->boolean('query_enabled')->default(false);
            $t->string('sector')->nullable();
            $t->json('service_ids')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('mapping_revision')->default(1);
            $t->timestampTz('query_checked_at')->nullable()->index();
            $t->timestampTz('last_query_success_at')->nullable();
            $t->unsignedBigInteger('query_import_id')->nullable();
            $t->string('query_error', 120)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('resource_query_batches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('automation_id')->constrained('resource_automations')->cascadeOnDelete();
            $t->unsignedBigInteger('dataset_run_id');
            $t->foreignId('import_id')->constrained('search_query_library_imports')->cascadeOnDelete();
            $t->unsignedBigInteger('cursor')->default(0);
            $t->unsignedBigInteger('upper_id')->default(0);
            $t->unsignedBigInteger('unassigned_rows')->default(0);
            $t->unsignedBigInteger('suppressed_rows')->default(0);
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampsTz();
            $t->unique(['automation_id', 'dataset_run_id'], 'rqb_dataset_uq');
        });
        Schema::create('resource_query_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('automation_id')->constrained('resource_automations')->cascadeOnDelete();
            $t->char('text_hash', 64);
            $t->text('original_text');
            $t->unsignedBigInteger('query_id')->nullable()->index();
            $t->string('decision', 24);
            $t->unsignedInteger('mapping_revision');
            $t->char('matching_fingerprint', 64)->nullable();
            $t->date('first_seen_date');
            $t->date('last_seen_date');
            $t->timestampsTz();
            $t->unique(['automation_id', 'text_hash'], 'rqo_text_uq');
        });
        Schema::create('library_query_aliases', function (Blueprint $t): void {
            $t->char('identity_hash', 64)->primary();
            $t->foreignId('query_id')->constrained('search_query_library_items')->cascadeOnDelete();
            $t->timestampsTz();
        });
        Schema::create('library_query_service_blocks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('query_id')->constrained('search_query_library_items')->cascadeOnDelete();
            $t->foreignId('service_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $t->timestampsTz();
            $t->unique(['query_id', 'service_id'], 'lqsb_pair_uq');
        });
        foreach (['gsc_query_daily', 'google_ads_search_term_daily'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->index(['external_resource_id', 'last_dataset_run_id', 'id'], $table === 'gsc_query_daily' ? 'gsc_auto_cursor_idx' : 'gads_auto_cursor_idx');
            });
        }
        // Preserve old names of existing manually renamed identities without moving data.
        DB::table('search_query_library_source_records')->where('source_type', 'manual')->orderBy('id')->chunkById(250, function ($rows): void {
            foreach ($rows as $row) {
                $raw = json_decode($row->raw_payload ?? '{}', true);
                if (($raw['action'] ?? '') !== 'rename' || empty($raw['previous_text'])) {
                    continue;
                }
                $hash = hash('sha256', 'library-location-free-v2|'.$raw['previous_text']);
                if (! DB::table('search_query_library_items')->where('identity_hash', $hash)->where('id', '!=', $row->search_query_library_item_id)->exists()) {
                    DB::table('library_query_aliases')->insertOrIgnore([
                        'identity_hash' => $hash, 'query_id' => $row->search_query_library_item_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('gsc_query_daily', fn (Blueprint $t) => $t->dropIndex('gsc_auto_cursor_idx'));
        Schema::table('google_ads_search_term_daily', fn (Blueprint $t) => $t->dropIndex('gads_auto_cursor_idx'));
        foreach (['library_query_service_blocks', 'library_query_aliases', 'resource_query_observations', 'resource_query_batches', 'resource_automations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
