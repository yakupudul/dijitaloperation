<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 59 — Immutable Report Snapshot production persistence.
 *
 * No PDF / share / delivery tables (Prompt 60).
 * No report-builder EAV. Typed JSON content + source manifest only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->string('report_type', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('comparison_period_start')->nullable();
            $table->date('comparison_period_end')->nullable();
            $table->string('title_snapshot', 255);
            $table->string('customer_name_snapshot', 255);
            $table->string('brand_name_snapshot', 255);
            $table->string('locale', 16);
            $table->string('reporting_timezone', 64);
            $table->string('snapshot_schema_version', 64);
            $table->json('content_payload');
            $table->json('source_manifest_payload');
            $table->string('source_manifest_fingerprint', 64);
            $table->string('content_checksum', 64);
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->foreignId('supersedes_snapshot_id')
                ->nullable()
                ->constrained('report_snapshots')
                ->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — content is immutable. Framework may ignore if cast absent.

            $table->index(['customer_id', 'generated_at'], 'report_snapshots_customer_generated_idx');
            $table->index(['brand_id', 'generated_at'], 'report_snapshots_brand_generated_idx');
            $table->index(['customer_id', 'brand_id', 'report_type'], 'report_snapshots_scope_type_idx');
            $table->index(['brand_id', 'period_start', 'period_end'], 'report_snapshots_period_idx');
            $table->index(['report_type', 'snapshot_schema_version'], 'report_snapshots_schema_idx');
            $table->index('source_manifest_fingerprint');
            $table->index('content_checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
