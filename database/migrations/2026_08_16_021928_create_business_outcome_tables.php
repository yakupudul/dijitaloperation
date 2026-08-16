<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 57 — Aggregate Business Outcome production persistence.
 *
 * No CRM / Lead / Patient / Deal / Appointment / Invoice tables.
 * Aggregate Brand-owned observations only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_outcome_definitions')) {
            Schema::create('business_outcome_definitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->string('kind', 32);
                $table->string('unit', 16);
                $table->string('code', 64);
                $table->string('display_label');
                $table->text('semantic_definition');
                $table->string('reporting_timezone', 64)->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->string('status', 32);
                $table->unsignedInteger('definition_version')->default(1);
                $table->foreignId('brand_goal_id')->nullable()->constrained('brand_goals')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['brand_id', 'code']);
                $table->index(['customer_id', 'brand_id', 'status']);
                $table->index(['brand_id', 'kind', 'status']);
            });
        }

        if (! Schema::hasTable('business_outcome_import_batches')) {
            Schema::create('business_outcome_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32);
                $table->string('file_checksum', 64);
                $table->string('original_filename', 255)->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedInteger('valid_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->unsignedInteger('committed_count')->default(0);
                $table->json('validation_errors')->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->index(['brand_id', 'status']);
                $table->index(['brand_id', 'file_checksum']);
            });
        }

        if (! Schema::hasTable('business_outcome_observations')) {
            Schema::create('business_outcome_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('definition_id')->constrained('business_outcome_definitions')->restrictOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 32);
                $table->unsignedBigInteger('current_revision_id')->nullable();
                $table->string('semantic_key', 64);
                $table->timestamps();

                $table->unique(['definition_id', 'semantic_key']);
                $table->index(['brand_id', 'definition_id', 'period_start', 'period_end'], 'bo_obs_period_idx');
                $table->index(['customer_id', 'brand_id', 'status']);
            });
        }

        if (! Schema::hasTable('business_outcome_observation_revisions')) {
            Schema::create('business_outcome_observation_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('observation_id')->constrained('business_outcome_observations')->cascadeOnDelete();
                $table->unsignedInteger('revision_number');
                $table->decimal('value_numeric', 18, 4);
                $table->unsignedBigInteger('value_count')->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->string('completeness', 32);
                $table->string('source_kind', 32);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at');
                $table->string('correction_reason', 500)->nullable();
                $table->foreignId('import_batch_id')
                    ->nullable()
                    ->constrained('business_outcome_import_batches')
                    ->nullOnDelete();
                $table->unsignedInteger('import_row_number')->nullable();
                $table->string('row_fingerprint', 64)->nullable();
                $table->string('source_note', 500)->nullable();
                $table->unsignedInteger('definition_version_snapshot');
                $table->string('semantic_definition_snapshot', 2000);
                $table->timestamps();

                $table->unique(['observation_id', 'revision_number']);
                $table->index(['observation_id', 'created_at']);
                $table->index('row_fingerprint');
            });
        }

        if (Schema::hasTable('brand_experience_revisions')
            && ! Schema::hasColumn('brand_experience_revisions', 'business_outcome_observation_revision_id')) {
            Schema::table('brand_experience_revisions', function (Blueprint $table): void {
                $table->foreignId('business_outcome_observation_revision_id')
                    ->nullable()
                    ->constrained('business_outcome_observation_revisions')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brand_experience_revisions')
            && Schema::hasColumn('brand_experience_revisions', 'business_outcome_observation_revision_id')) {
            Schema::table('brand_experience_revisions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('business_outcome_observation_revision_id');
            });
        }

        Schema::dropIfExists('business_outcome_observation_revisions');
        Schema::dropIfExists('business_outcome_observations');
        Schema::dropIfExists('business_outcome_import_batches');
        Schema::dropIfExists('business_outcome_definitions');
    }
};
