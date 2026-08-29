<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_cms_object_snapshot')) {
            return;
        }

        Schema::create('website_cms_object_snapshot', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('external_resource_id')->nullable();
            $table->text('cms');
            $table->text('object_type');
            $table->text('object_id');
            $table->text('status')->nullable();
            $table->text('slug')->nullable();
            $table->text('permalink')->nullable();
            $table->text('title')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('modified_at')->nullable();
            $table->text('parent_id')->nullable();
            $table->text('template')->nullable();
            $table->text('featured_media_id')->nullable();
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
                ['digital_asset_id', 'cms', 'object_type', 'object_id'],
                'website_cms_object_snapshot_nk_unique'
            );
            $table->index(['digital_asset_id', 'object_type'], 'website_cms_object_snapshot_asset_type_idx');
            $table->index(['digital_asset_id', 'permalink'], 'website_cms_object_snapshot_asset_url_idx');
            $table->index(['digital_asset_id', 'modified_at'], 'website_cms_object_snapshot_asset_modified_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_cms_object_snapshot');
    }
};
