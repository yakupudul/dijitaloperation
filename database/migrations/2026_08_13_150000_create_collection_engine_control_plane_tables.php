<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collection engine control-plane tables (Prompt 9).
 *
 * Distinct from legacy `runs` / Evidence Activity Center path.
 * No provider fact / warehouse tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
            $table->string('trigger_type', 32);
            $table->string('status', 40)->index();
            $table->string('contract_registry_id', 64);
            $table->unsignedInteger('contract_registry_version');
            $table->string('contract_registry_checksum', 64)->nullable();
            $table->unsignedInteger('formula_registry_version')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->unsignedInteger('resources_total')->default(0);
            $table->unsignedInteger('resources_completed')->default(0);
            $table->unsignedInteger('datasets_total')->default(0);
            $table->unsignedInteger('datasets_completed')->default(0);
            $table->unsignedInteger('datasets_failed')->default(0);
            $table->string('failure_summary', 512)->nullable();
            $table->json('request_context')->nullable();
            $table->json('plan_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['digital_asset_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('collection_resource_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('collection_run_id')->constrained('collection_runs')->cascadeOnDelete();
            $table->string('provider_or_source', 64)->index();
            $table->string('resource_kind', 64);
            $table->foreignId('external_resource_id')->nullable()->constrained('core_external_resources')->nullOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
            $table->foreignId('core_asset_binding_id')->nullable()->constrained('core_asset_bindings')->nullOnDelete();
            $table->string('status', 40)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('datasets_total')->default(0);
            $table->unsignedInteger('datasets_completed')->default(0);
            $table->unsignedInteger('datasets_failed')->default(0);
            $table->string('error_summary', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['collection_run_id', 'status']);
        });

        Schema::create('collection_dataset_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('collection_run_id')->constrained('collection_runs')->cascadeOnDelete();
            $table->foreignId('collection_resource_run_id')->constrained('collection_resource_runs')->cascadeOnDelete();
            $table->string('provider_or_source', 64)->index();
            $table->string('dataset_contract_id', 128);
            $table->string('request_family_id', 128);
            $table->string('requirement_level', 32);
            $table->unsignedInteger('contract_registry_version');
            $table->string('status', 40)->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('retry_at')->nullable()->index();
            $table->string('progress_mode', 32)->default('indeterminate');
            $table->unsignedBigInteger('progress_current')->nullable();
            $table->unsignedBigInteger('progress_total')->nullable();
            $table->unsignedBigInteger('rows_received')->default(0);
            $table->unsignedBigInteger('rows_written')->default(0);
            $table->unsignedInteger('chunks_completed')->default(0);
            $table->unsignedInteger('chunks_failed')->default(0);
            $table->unsignedInteger('pages_completed')->default(0);
            $table->string('stage')->nullable();
            $table->json('checkpoint')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->string('error_message', 512)->nullable();
            $table->json('depends_on_dataset_run_ids')->nullable();
            $table->string('dispatch_lock_token', 64)->nullable();
            $table->timestamp('dispatch_locked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['collection_run_id', 'status']);
            $table->index(['collection_resource_run_id', 'status']);
            $table->index(['request_family_id', 'status']);
            $table->unique(
                ['collection_run_id', 'collection_resource_run_id', 'dataset_contract_id', 'request_family_id'],
                'collection_dataset_runs_scope_unique'
            );
        });

        Schema::create('collection_dataset_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_dataset_run_id')->constrained('collection_dataset_runs')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 40);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->string('error_message', 512)->nullable();
            $table->timestamp('retry_scheduled_at')->nullable();
            $table->string('job_uuid', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['collection_dataset_run_id', 'attempt_number'], 'collection_dataset_attempts_unique');
            $table->index(['collection_dataset_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_dataset_attempts');
        Schema::dropIfExists('collection_dataset_runs');
        Schema::dropIfExists('collection_resource_runs');
        Schema::dropIfExists('collection_runs');
    }
};
