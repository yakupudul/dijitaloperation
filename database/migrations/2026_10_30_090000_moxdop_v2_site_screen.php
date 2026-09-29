<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 4a (Web sitesi ekranı, SEO çekirdeği).
 *
 * 1. pages.category_locked / category_source: rule | ai | manual; the operator's category is locked.
 * 2. offering_pages: AI adım 1 — brand service ↔ page (rule | ai | manual, locked).
 * 3. brand_cluster_pages: stored 28-day numbers + one-line reason + who decided, so the screen only reads.
 * 4. digital_assets.sitemap_url (Ayarlar override); brands.weekly_content_capacity (İçerik, default 4 / week).
 * 5. website_standard_settings: scope (url | brand | sector | general), scope id, version, source suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pages', 'category_locked')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->boolean('category_locked')->default(false)->after('category');
                $table->string('category_source', 8)->nullable()->after('category_locked'); // rule | ai | manual
            });
        }

        if (! Schema::hasTable('offering_pages')) {
            Schema::create('offering_pages', function (Blueprint $table): void {
                $table->id();
                // null + locked = the operator said "no service" for this page
                $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->cascadeOnDelete();
                $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
                $table->string('source', 8)->default('rule'); // rule | ai | manual
                $table->boolean('locked')->default(false);
                $table->timestampsTz();

                $table->unique(['brand_offering_id', 'page_id'], 'offering_pages_offering_page_uq');
                $table->index('page_id', 'offering_pages_page_idx');
            });
        }

        if (! Schema::hasColumn('brand_cluster_pages', 'clicks_28d')) {
            Schema::table('brand_cluster_pages', function (Blueprint $table): void {
                $table->unsignedBigInteger('clicks_28d')->nullable()->after('language');
                $table->unsignedBigInteger('impressions_28d')->nullable()->after('clicks_28d');
                $table->decimal('position_28d', 8, 2)->nullable()->after('impressions_28d');
                $table->string('reason', 300)->nullable()->after('position_28d');
                $table->string('decided_by', 8)->nullable()->after('reason'); // rule | ai | manual
                $table->timestampTz('refreshed_at')->nullable()->after('decided_by');
            });
        }

        if (! Schema::hasColumn('digital_assets', 'sitemap_url')) {
            Schema::table('digital_assets', fn (Blueprint $table) => $table->text('sitemap_url')->nullable());
        }
        if (! Schema::hasColumn('brands', 'weekly_content_capacity')) {
            Schema::table('brands', fn (Blueprint $table) => $table->unsignedTinyInteger('weekly_content_capacity')->default(4));
        }

        if (! Schema::hasColumn('website_standard_settings', 'scope_type')) {
            Schema::table('website_standard_settings', function (Blueprint $table): void {
                $table->string('scope_type', 8)->nullable()->after('custom_definition'); // url | brand | sector | general
                $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
                $table->unsignedInteger('version')->default(1)->after('scope_id');
                $table->unsignedBigInteger('created_from_suggestion_id')->nullable()->after('version');
                $table->index(['scope_type', 'scope_id'], 'website_standard_settings_scope_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('website_standard_settings', 'scope_type')) {
            Schema::table('website_standard_settings', fn (Blueprint $table) => $table->dropIndex('website_standard_settings_scope_idx'));
            Schema::table('website_standard_settings', fn (Blueprint $table) => $table->dropColumn(['scope_type', 'scope_id', 'version', 'created_from_suggestion_id']));
        }
        if (Schema::hasColumn('brands', 'weekly_content_capacity')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('weekly_content_capacity'));
        }
        if (Schema::hasColumn('digital_assets', 'sitemap_url')) {
            Schema::table('digital_assets', fn (Blueprint $table) => $table->dropColumn('sitemap_url'));
        }
        if (Schema::hasColumn('brand_cluster_pages', 'clicks_28d')) {
            Schema::table('brand_cluster_pages', fn (Blueprint $table) => $table->dropColumn(['clicks_28d', 'impressions_28d', 'position_28d', 'reason', 'decided_by', 'refreshed_at']));
        }
        Schema::dropIfExists('offering_pages');
        if (Schema::hasColumn('pages', 'category_locked')) {
            Schema::table('pages', fn (Blueprint $table) => $table->dropColumn(['category_locked', 'category_source']));
        }
    }
};
