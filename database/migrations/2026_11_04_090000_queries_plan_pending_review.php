<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgular: AI ile planla, negatif filtre, bekleyen sorgular, onaylı tarama.
 * 1. digital_assets.sector_id: the asset's own sector override (null = the brand's sector).
 * 2. agency_settings.queries_imported_at: the first (only automatic) bulk import into the library is done.
 * 3. pending_queries: new queries after the first import (pending), dismissed ones and deleted ones (never come back).
 * 4. query_reviews + query_review_items: rescan results (queries to delete, service changes) waiting for approval.
 * 5. user_notifications.toasted_at: an important notification was shown once as a toast.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('digital_assets', 'sector_id')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                // No FK (like merged_into_asset_id): a rebuilt digital_assets table must not cascade; a deleted sector
                // is cleared by the sector catalog screen and ignored by DigitalAsset::sector().
                $table->unsignedBigInteger('sector_id')->nullable()->index('digital_assets_sector_idx');
            });
        }
        if (! Schema::hasColumn('agency_settings', 'queries_imported_at')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->timestampTz('queries_imported_at')->nullable();
            });
        }
        if (! Schema::hasColumn('user_notifications', 'toasted_at')) {
            Schema::table('user_notifications', function (Blueprint $table): void {
                $table->timestamp('toasted_at')->nullable();
            });
        }

        Schema::create('pending_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('text', 500);
            $table->char('text_hash', 64)->unique('pending_queries_text_hash_uq');
            $table->string('status', 12)->default('pending'); // pending | dismissed | deleted
            $table->foreignId('external_resource_id')->nullable()->constrained('core_external_resources')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->string('filter_term', 200)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'impressions'], 'pending_queries_status_impressions_idx');
        });

        Schema::create('query_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 12)->default('running'); // running | ready | applied | failed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changes')->default(0);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('query_review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('query_review_id')->constrained('query_reviews')->cascadeOnDelete();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete();
            $table->string('kind', 8); // delete | service
            $table->string('term', 200)->nullable();
            $table->foreignId('from_service_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->foreignId('to_service_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();

            $table->index(['query_review_id', 'kind'], 'query_review_items_review_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_review_items');
        Schema::dropIfExists('query_reviews');
        Schema::dropIfExists('pending_queries');
        foreach ([['user_notifications', 'toasted_at'], ['agency_settings', 'queries_imported_at']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasColumn('digital_assets', 'sector_id')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                $table->dropIndex('digital_assets_sector_idx');
                $table->dropColumn('sector_id');
            });
        }
    }
};
