<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 4b (Site ekranı: Rakipler, Backlinkler, Site Sağlığı): competitor SERP per brand cluster, competitor
 * page contents, domain classes, backlinks (imported / manual), potential backlink sources, SSL / domain expiry checks
 * and the manual hosting expiry date on the website asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_cluster_serps')) {
            return;
        }

        Schema::create('competitor_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 255)->unique('competitor_domains_domain_uq');
            $table->string('class', 16); // ticari | bilgi | dizin | haber
            $table->string('method', 8); // rule | ai | manual
            $table->string('reason', 240)->nullable();
            $table->timestampsTz();
        });

        Schema::create('brand_cluster_serps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('query', 500);
            $table->unsignedInteger('location_code')->nullable();
            $table->string('language_code', 8)->nullable();
            $table->string('device', 8)->default('mobile');
            $table->unsignedSmallInteger('own_rank')->nullable();
            $table->json('results')->nullable(); // [{rank, url, domain, title, class}]
            $table->string('status', 16)->default('ready'); // ready | error
            $table->string('error', 500)->nullable();
            $table->json('analysis')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'cluster_id', 'website_asset_id'], 'brand_cluster_serps_uq');
        });

        Schema::create('competitor_pages', function (Blueprint $table): void {
            $table->id();
            $table->text('url');
            $table->char('url_hash', 64)->unique('competitor_pages_url_uq');
            $table->string('domain', 255);
            $table->string('class', 16)->nullable();
            $table->string('title', 500)->nullable();
            $table->json('headings')->nullable();
            $table->text('content_text')->nullable();
            $table->string('status', 8)->default('ok'); // ok | eksik
            $table->string('error', 240)->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampsTz();

            $table->index('domain', 'competitor_pages_domain_idx');
        });

        Schema::create('backlinks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->text('source_url')->nullable();
            $table->string('source_domain', 255);
            $table->text('target_url')->nullable();
            $table->char('link_hash', 64);
            $table->date('first_seen')->nullable();
            $table->string('source', 16); // gsc_import | manual
            $table->string('status', 16)->default('aktif'); // aktif | kaldirildi
            $table->timestampsTz();

            $table->unique(['brand_id', 'link_hash'], 'backlinks_brand_link_uq');
            $table->index(['brand_id', 'source_domain'], 'backlinks_brand_domain_idx');
        });

        Schema::create('backlink_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('url');
            $table->string('domain', 255);
            $table->string('kind', 16)->default('diger'); // dizin | dernek | yerel_haber | oda | diger
            $table->string('fee', 16)->default('teyit'); // ucretsiz | ucretli | teyit
            $table->text('fee_evidence_url')->nullable();
            $table->string('reason', 240)->nullable();
            $table->string('origin', 8)->default('manual'); // ai | manual
            $table->string('status', 16)->default('yok'); // yok | basvuru | verildi | dogrulandi | kaldirildi
            $table->text('link_url')->nullable();
            $table->string('note', 240)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'domain'], 'backlink_sources_brand_domain_uq');
        });

        Schema::create('website_expiry_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->unique('website_expiry_checks_site_uq')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('host', 255)->nullable();
            $table->timestampTz('ssl_expires_at')->nullable();
            $table->string('ssl_issuer', 255)->nullable();
            $table->string('ssl_error', 240)->nullable();
            $table->string('registrable_domain', 255)->nullable();
            $table->timestampTz('domain_expires_at')->nullable();
            $table->string('domain_error', 240)->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
        });

        if (! Schema::hasColumn('digital_assets', 'hosting_expires_on')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                $table->date('hosting_expires_on')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['website_expiry_checks', 'backlink_sources', 'backlinks', 'competitor_pages', 'brand_cluster_serps', 'competitor_domains'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('digital_assets', 'hosting_expires_on')) {
            Schema::table('digital_assets', fn (Blueprint $table) => $table->dropColumn('hosting_expires_on'));
        }
    }
};
