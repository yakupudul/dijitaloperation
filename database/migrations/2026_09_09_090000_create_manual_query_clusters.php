<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_query_clusters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('service_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $t->string('name');
            $t->string('name_key')->nullable();
            $t->text('description')->nullable();
            $t->string('status', 24)->default('active');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['service_id', 'name_key'], 'lqc_name_uq');
        });
        Schema::table('search_query_library_item_service', function (Blueprint $t): void {
            $t->foreignId('library_cluster_id')->nullable()->constrained('library_query_clusters')->restrictOnDelete();
            $t->timestampTz('cluster_reviewed_at')->nullable();
            $t->unsignedBigInteger('cluster_revision')->default(0);
            $t->index(['service_catalog_item_id', 'library_cluster_id', 'id'], 'lqc_members_idx');
        });
        Schema::create('library_cluster_operations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('service_id')->constrained('service_catalog_items')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 24);
            $t->string('status', 24)->default('queued');
            $t->unsignedBigInteger('source_cluster_id')->nullable();
            $t->unsignedBigInteger('target_cluster_id')->nullable();
            $t->unsignedBigInteger('undo_of')->nullable()->unique();
            $t->json('metadata');
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('processed')->default(0);
            $t->unsignedBigInteger('changed')->default(0);
            $t->unsignedBigInteger('skipped')->default(0);
            $t->string('file_path')->nullable();
            $t->text('error')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['service_id', 'status'], 'lqc_ops_state_idx');
        });
        Schema::create('library_cluster_operation_rows', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('operation_id')->constrained('library_cluster_operations')->cascadeOnDelete();
            $t->unsignedBigInteger('membership_id');
            $t->unsignedBigInteger('query_id');
            $t->text('query_text');
            $t->string('cluster_name');
            $t->unsignedBigInteger('before_cluster_id')->nullable();
            $t->timestampTz('before_reviewed_at')->nullable();
            $t->unsignedBigInteger('before_revision');
            $t->unsignedBigInteger('after_revision')->nullable();
            $t->unsignedBigInteger('restore_cluster_id')->nullable();
            $t->timestampTz('restore_reviewed_at')->nullable();
            $t->string('decision', 24)->default('pending');
            $t->unique(['operation_id', 'membership_id'], 'lqc_receipt_uq');
            $t->index(['operation_id', 'decision', 'id'], 'lqc_pending_idx');
        });
        Schema::create('library_cluster_targets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('service_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $t->unsignedBigInteger('cluster_key')->default(0);
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->text('url');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['service_id', 'cluster_key', 'digital_asset_id'], 'lqc_site_target_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_cluster_targets');
        Schema::dropIfExists('library_cluster_operation_rows');
        Schema::dropIfExists('library_cluster_operations');
        Schema::table('search_query_library_item_service', function (Blueprint $t): void {
            $t->dropIndex('lqc_members_idx');
            $t->dropConstrainedForeignId('library_cluster_id');
            $t->dropColumn(['cluster_reviewed_at', 'cluster_revision']);
        });
        Schema::dropIfExists('library_query_clusters');
    }
};

