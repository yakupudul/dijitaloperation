<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 10 data-pool control tables (raw manifest, write batches, materializations).
 * Additive only — does not alter CollectionRun / Evidence / Integration tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_ingestion_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('collection_run_id')->nullable()->constrained('collection_runs')->restrictOnDelete();
            $table->foreignId('resource_run_id')->nullable()->constrained('collection_resource_runs')->restrictOnDelete();
            $table->foreignId('dataset_run_id')->nullable()->constrained('collection_dataset_runs')->restrictOnDelete();
            $table->string('dataset_id');
            $table->string('request_family_id')->nullable();
            $table->string('batch_key');
            $table->string('provider_or_source');
            $table->string('storage_disk');
            $table->string('object_key');
            $table->string('content_type')->nullable();
            $table->string('compression')->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('record_count')->nullable();
            $table->string('provider_request_fingerprint')->nullable();
            $table->timestampTz('captured_at');
            $table->string('retention_class')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['dataset_run_id', 'batch_key'], 'raw_ingestion_objects_run_batch_unique');
            $table->unique(['storage_disk', 'object_key'], 'raw_ingestion_objects_disk_key_unique');
            $table->index(['dataset_id', 'captured_at'], 'raw_ingestion_objects_dataset_captured_idx');
        });

        Schema::create('dataset_write_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_run_id')->constrained('collection_dataset_runs')->restrictOnDelete();
            $table->string('batch_key');
            $table->string('idempotency_key')->unique();
            $table->foreignId('raw_ingestion_object_id')->nullable()->constrained('raw_ingestion_objects')->nullOnDelete();
            $table->string('dataset_id');
            $table->string('status'); // pending|committed|failed
            $table->unsignedInteger('rows_received')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_unchanged')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->unique(['dataset_run_id', 'batch_key'], 'dataset_write_batches_run_batch_unique');
            $table->index(['dataset_id', 'status'], 'dataset_write_batches_dataset_status_idx');
        });

        Schema::create('dataset_materializations', function (Blueprint $table) {
            $table->id();
            $table->string('dataset_id');
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedBigInteger('external_resource_id')->nullable();
            $table->string('provider_or_source');
            $table->unsignedInteger('contract_version')->default(1);
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            $table->foreignId('last_successful_collection_run_id')->nullable()->constrained('collection_runs')->nullOnDelete();
            $table->foreignId('last_successful_dataset_run_id')->nullable()->constrained('collection_dataset_runs')->nullOnDelete();
            $table->timestampTz('last_collected_at')->nullable();
            $table->timestampTz('last_source_data_at')->nullable();
            $table->unsignedBigInteger('row_count_approx')->default(0);
            $table->string('row_count_semantics')->default('approximate_from_batches');
            $table->string('status'); // NOT_COLLECTED|AVAILABLE|PARTIAL|STALE|UNAVAILABLE
            $table->boolean('partial')->default(false);
            $table->json('freshness_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['dataset_id', 'digital_asset_id', 'external_resource_id', 'contract_version'],
                'dataset_materializations_scope_unique'
            );
            $table->index(['digital_asset_id', 'dataset_id'], 'dataset_materializations_asset_dataset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_materializations');
        Schema::dropIfExists('dataset_write_batches');
        Schema::dropIfExists('raw_ingestion_objects');
    }
};
