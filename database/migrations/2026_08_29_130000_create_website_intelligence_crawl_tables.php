<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_link_edge')) {
            Schema::create('website_link_edge', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->char('edge_key', 64);
                $table->text('source_url');
                $table->text('target_url');
                $table->text('normalized_target_url');
                $table->boolean('is_internal')->default(false);
                $table->text('anchor_text')->nullable();
                $table->string('rel', 255)->nullable();
                $table->boolean('nofollow')->default(false);
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['digital_asset_id', 'edge_key', 'observed_at'], 'website_link_edge_nk_unique');
                $table->index(['digital_asset_id', 'is_internal'], 'website_link_edge_asset_internal_idx');
                $table->index(['digital_asset_id', 'observed_at'], 'website_link_edge_asset_observed_idx');
            });
        }

        if (! Schema::hasTable('website_crawl_issue_snapshot')) {
            Schema::create('website_crawl_issue_snapshot', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->string('issue_code', 96);
                $table->string('severity', 24);
                $table->text('message');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['digital_asset_id', 'url', 'issue_code', 'observed_at'], 'website_crawl_issue_nk_unique');
                $table->index(['digital_asset_id', 'severity'], 'website_crawl_issue_asset_severity_idx');
                $table->index(['digital_asset_id', 'observed_at'], 'website_crawl_issue_asset_observed_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_crawl_issue_snapshot');
        Schema::dropIfExists('website_link_edge');
    }
};
