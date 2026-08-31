<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_html_snapshot')) {
            return;
        }

        Schema::create('website_html_snapshot', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('external_resource_id')->nullable();
            $table->text('url');
            $table->text('requested_url')->nullable();
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->char('html_hash', 64);
            $table->char('previous_html_hash', 64)->nullable();
            $table->string('change_state', 20);
            $table->unsignedBigInteger('html_bytes');
            $table->foreignId('raw_ingestion_object_id')->nullable()->constrained('raw_ingestion_objects')->nullOnDelete();
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

            $table->unique(
                ['digital_asset_id', 'url', 'observed_at'],
                'website_html_snapshot_asset_url_observed_unique',
            );
            $table->index(
                ['digital_asset_id', 'url', 'observed_at'],
                'website_html_snapshot_asset_url_observed_idx',
            );
            $table->index(
                ['digital_asset_id', 'html_hash'],
                'website_html_snapshot_asset_hash_idx',
            );
            $table->index(
                ['digital_asset_id', 'change_state'],
                'website_html_snapshot_asset_change_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_html_snapshot');
    }
};
