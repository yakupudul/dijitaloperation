<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink hizmeti, adım 1 (yakup 2026-11-06 "Sırayla hepsini yap"; doc "Backlink hizmeti mimarisi"):
 * 1. link_sources: ONE shared pool of places that give links, bound to a sector and a city (not to a brand), with how
 *    to apply, a quality verdict and what it brought so far (applied / won / days until the link came).
 * 2. link_targets: the pages of a brand that need link strength this month (Hedef motoru), why, the numbers on the day
 *    they were picked (the before side of the 60 / 90 day comparison) and the link text plan.
 * 3. backlink_sources (the brand's rows) becomes the Bağlantı masası row: pool source + target page + engine + the
 *    ready material (profile text, form values, e-mail, phone note) + dates of each step.
 * 4. brands.link_package: the monthly link budget (temel 2, buyume 4, otorite 6, kapali 0).
 * Existing rows are copied into the pool once (one row per domain).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('link_sources')) {
            Schema::create('link_sources', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 255)->unique('link_sources_domain_uq');
                $table->string('name', 200);
                $table->text('url');
                $table->string('kind', 16)->default('diger');
                $table->foreignId('sector_id')->nullable()->constrained('service_categories')->nullOnDelete();
                $table->string('city', 80)->nullable(); // folded city name; null = the whole country
                $table->string('apply_via', 16)->default('bilinmiyor'); // form | eposta | telefon | uyelik | bilinmiyor
                $table->text('apply_url')->nullable();
                $table->string('contact_email', 255)->nullable();
                $table->string('fee', 16)->default('teyit'); // ucretsiz | ucretli | teyit
                $table->text('fee_evidence_url')->nullable();
                $table->string('quality', 16)->default('bekliyor'); // bekliyor | uygun | red
                $table->string('quality_note', 300)->nullable();
                $table->unsignedSmallInteger('authority')->nullable();
                $table->unsignedInteger('applied_count')->default(0);
                $table->unsignedInteger('won_count')->default(0);
                $table->unsignedInteger('days_total')->default(0);
                $table->string('origin', 8)->default('ai'); // ai | manual | serp
                $table->timestampTz('checked_at')->nullable();
                $table->timestampsTz();

                $table->index(['sector_id', 'city'], 'link_sources_sector_city_idx');
            });
        }

        if (! Schema::hasTable('link_targets')) {
            Schema::create('link_targets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->foreignId('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
                $table->text('url');
                $table->string('url_key', 64);
                $table->string('title', 300)->nullable();
                $table->string('query', 300)->nullable();
                $table->string('reason', 400)->nullable();
                $table->string('status', 16)->default('aktif'); // aktif | tamam | cikti
                $table->unsignedSmallInteger('rank')->default(1);
                $table->float('position')->nullable();
                $table->unsignedInteger('impressions')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('referring_domains')->default(0);
                $table->json('anchor_plan')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestampTz('picked_at');
                $table->timestampTz('released_at')->nullable();
                $table->timestampsTz();

                $table->unique(['brand_id', 'url_key'], 'link_targets_brand_url_uq');
                $table->index(['brand_id', 'status'], 'link_targets_brand_status_idx');
            });
        }

        if (! Schema::hasColumn('backlink_sources', 'engine')) {
            Schema::table('backlink_sources', function (Blueprint $table): void {
                $table->foreignId('link_source_id')->nullable()->constrained('link_sources')->nullOnDelete();
                $table->foreignId('link_target_id')->nullable()->constrained('link_targets')->nullOnDelete();
                $table->string('engine', 16)->default('kunye'); // geri_kazanim | kunye | iliski | anilma | rakip | miknatis | veri
                $table->string('lane', 8)->default('masa'); // masa | sen (phone, visit, sponsorship: the operator does it)
                $table->string('anchor', 200)->nullable();
                $table->json('material')->nullable();
                $table->string('material_status', 16)->nullable(); // queued | running | waiting | ready | failed
                $table->string('material_note', 1000)->nullable();
                $table->timestampTz('sent_at')->nullable();
                $table->timestampTz('given_at')->nullable();
                $table->timestampTz('next_step_at')->nullable();
                $table->boolean('paid')->default(false);
                $table->string('rel', 16)->nullable(); // follow | nofollow | sponsored | ugc
                $table->index(['status', 'lane'], 'backlink_sources_status_lane_idx');
            });
        }

        if (! Schema::hasColumn('brands', 'link_package')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->string('link_package', 12)->default('temel'); // kapali | temel | buyume | otorite
            });
        }

        // The rows proposed so far become the first pool, one per domain, in the sector of the brand that found them.
        $now = now();
        foreach (DB::table('backlink_sources')->join('brands', 'brands.id', '=', 'backlink_sources.brand_id')->whereNull('backlink_sources.link_source_id')
            ->orderBy('backlink_sources.id')->get(['backlink_sources.*', 'brands.sector_id as brand_sector']) as $row) {
            $poolId = DB::table('link_sources')->where('domain', $row->domain)->value('id');
            if ($poolId === null) {
                $poolId = DB::table('link_sources')->insertGetId([
                    'domain' => $row->domain, 'name' => $row->name, 'url' => $row->url, 'kind' => $row->kind, 'sector_id' => $row->brand_sector,
                    'fee' => $row->fee, 'fee_evidence_url' => $row->fee_evidence_url, 'origin' => $row->origin === 'manual' ? 'manual' : 'ai',
                    'quality' => in_array($row->status, ['dogrulandi', 'verildi'], true) ? 'uygun' : 'bekliyor',
                    'applied_count' => in_array($row->status, ['basvuru', 'verildi', 'dogrulandi', 'kaldirildi'], true) ? 1 : 0,
                    'won_count' => in_array($row->status, ['dogrulandi', 'kaldirildi'], true) ? 1 : 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('backlink_sources')->where('id', $row->id)->update(['link_source_id' => $poolId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('backlink_sources', 'engine')) {
            Schema::table('backlink_sources', function (Blueprint $table): void {
                $table->dropIndex('backlink_sources_status_lane_idx');
                $table->dropConstrainedForeignId('link_source_id');
                $table->dropConstrainedForeignId('link_target_id');
                $table->dropColumn(['engine', 'lane', 'anchor', 'material', 'material_status', 'material_note', 'sent_at', 'given_at', 'next_step_at', 'paid', 'rel']);
            });
        }
        if (Schema::hasColumn('brands', 'link_package')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('link_package'));
        }
        Schema::dropIfExists('link_targets');
        Schema::dropIfExists('link_sources');
    }
};
