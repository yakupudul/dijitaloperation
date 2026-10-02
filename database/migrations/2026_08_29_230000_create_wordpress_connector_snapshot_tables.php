<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_cms_site_snapshot')) {
            Schema::create('website_cms_site_snapshot', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('cms');
                $table->text('site_key');
                $table->text('site_url');
                $table->text('home_url')->nullable();
                $table->text('wordpress_version')->nullable();
                $table->text('php_version')->nullable();
                $table->text('locale')->nullable();
                $table->text('timezone')->nullable();
                $table->text('active_theme')->nullable();
                $table->boolean('is_multisite')->default(false);
                $table->text('rest_state')->nullable();
                $table->text('cron_state')->nullable();
                $table->integer('site_health_good_count')->nullable();
                $table->integer('site_health_recommended_count')->nullable();
                $table->integer('site_health_critical_count')->nullable();
                $this->provenance($table);

                $table->unique(['digital_asset_id', 'cms', 'site_key', 'observed_at'], 'website_cms_site_snapshot_nk_unique');
                $table->index(['digital_asset_id', 'last_collected_at'], 'website_cms_site_snapshot_asset_collected_idx');
            });
        }

        if (! Schema::hasTable('website_cms_extension_snapshot')) {
            Schema::create('website_cms_extension_snapshot', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('cms');
                $table->text('extension_type');
                $table->text('extension_id');
                $table->text('name')->nullable();
                $table->text('version')->nullable();
                $table->text('status')->nullable();
                $table->boolean('update_available')->default(false);
                $table->text('available_version')->nullable();
                $table->boolean('auto_update')->nullable();
                $this->provenance($table);

                $table->unique(
                    ['digital_asset_id', 'cms', 'extension_type', 'extension_id', 'observed_at'],
                    'website_cms_extension_snapshot_nk_unique',
                );
                $table->index(
                    ['digital_asset_id', 'extension_type', 'update_available'],
                    'website_cms_extension_snapshot_asset_update_idx',
                );
            });
        }

        if (! Schema::hasTable('website_cms_taxonomy_snapshot')) {
            Schema::create('website_cms_taxonomy_snapshot', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('cms');
                $table->text('taxonomy');
                $table->text('term_id');
                $table->text('name')->nullable();
                $table->text('slug')->nullable();
                $table->text('parent_id')->nullable();
                $table->integer('content_count')->nullable();
                $table->text('language')->nullable();
                $this->provenance($table);

                $table->unique(
                    ['digital_asset_id', 'cms', 'taxonomy', 'term_id', 'observed_at'],
                    'website_cms_taxonomy_snapshot_nk_unique',
                );
                $table->index(['digital_asset_id', 'taxonomy'], 'website_cms_taxonomy_snapshot_asset_tax_idx');
            });
        }

        if (! Schema::hasTable('website_cms_seo_snapshot')) {
            Schema::create('website_cms_seo_snapshot', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id');
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('cms');
                $table->text('object_type');
                $table->text('object_id');
                $table->text('permalink')->nullable();
                $table->text('seo_provider')->nullable();
                $table->text('seo_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->text('canonical_url')->nullable();
                $table->text('robots')->nullable();
                $table->text('language')->nullable();
                $this->provenance($table);

                $table->unique(
                    ['digital_asset_id', 'cms', 'object_type', 'object_id', 'observed_at'],
                    'website_cms_seo_snapshot_nk_unique',
                );
                $table->index(['digital_asset_id', 'permalink'], 'website_cms_seo_snapshot_asset_url_idx');
            });
        }

        Schema::table('website_cms_object_snapshot', function (Blueprint $table): void {
            $table->dropUnique('website_cms_object_snapshot_nk_unique');
            $table->unique(
                ['digital_asset_id', 'cms', 'object_type', 'object_id', 'observed_at'],
                'website_cms_object_snapshot_nk_unique',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('website_cms_object_snapshot')) {
            Schema::table('website_cms_object_snapshot', function (Blueprint $table): void {
                $table->dropUnique('website_cms_object_snapshot_nk_unique');
                $table->unique(
                    ['digital_asset_id', 'cms', 'object_type', 'object_id'],
                    'website_cms_object_snapshot_nk_unique',
                );
            });
        }
        Schema::dropIfExists('website_cms_seo_snapshot');
        Schema::dropIfExists('website_cms_taxonomy_snapshot');
        Schema::dropIfExists('website_cms_extension_snapshot');
        Schema::dropIfExists('website_cms_site_snapshot');
    }

    private function provenance(Blueprint $table): void
    {
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
    }
};
