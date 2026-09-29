<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 · Faz 2 (Sahiplik).
 *
 * 1. brands.sector_id — the ONLY place a sector lives (service_categories id). Backfilled from the legacy code column
 *    `brands.sector` or the first `brand_service_category` row; assets read their sector through their brand.
 * 2. resource_automations.sector (retired per-account sector) is dropped.
 * 3. brand_candidates + brand_candidate_resources — discovered websites / accounts grouped into brand proposals.
 * 4. brand_service_candidates — services proposed from the brand's own pages, reviewed by the operator.
 * 5. brand_offerings.locked (approved by the operator: AI never renames), brand_service_areas.name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'sector_id')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->foreignId('sector_id')->nullable()->after('sector')->constrained('service_categories')->nullOnDelete();
            });
        }
        $this->backfillBrandSectors();

        if (Schema::hasColumn('resource_automations', 'sector')) {
            Schema::table('resource_automations', fn (Blueprint $table) => $table->dropColumn('sector'));
        }

        Schema::create('brand_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('group_key', 191)->nullable();
            $table->json('signals')->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('sector_signal', 24)->nullable(); // gbp_category | site | ads | manual
            $table->string('sector_reason', 255)->nullable();
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('method', 16)->default('deterministic'); // deterministic | ai | manual
            $table->string('status', 16)->default('proposed'); // proposed | approved | dismissed
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['status'], 'brand_candidates_status_idx');
        });

        Schema::create('brand_candidate_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_candidate_id')->constrained('brand_candidates')->cascadeOnDelete();
            $table->foreignId('external_resource_id')->nullable()->constrained('core_external_resources')->cascadeOnDelete();
            $table->foreignId('website_asset_id')->nullable()->constrained('digital_assets')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestampsTz();

            // A discovered resource / website belongs to at most one candidate.
            $table->unique(['external_resource_id'], 'brand_candidate_resources_resource_uq');
            $table->unique(['website_asset_id'], 'brand_candidate_resources_website_uq');
        });

        Schema::create('brand_service_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('normalized_key', 191);
            $table->foreignId('service_catalog_item_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->boolean('new_catalog_item')->default(false);
            $table->json('page_ids')->nullable();
            $table->string('status', 16)->default('proposed'); // proposed | approved | skipped
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['brand_id', 'normalized_key'], 'brand_service_candidates_brand_key_uq');
        });

        if (! Schema::hasColumn('brand_offerings', 'locked')) {
            Schema::table('brand_offerings', function (Blueprint $table): void {
                $table->boolean('locked')->default(false);
            });
        }
        if (! Schema::hasColumn('brand_service_areas', 'name')) {
            Schema::table('brand_service_areas', function (Blueprint $table): void {
                $table->string('name', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        // SQLite rebuilds `brands` to drop the foreign key: keep cascades from deleting child rows meanwhile.
        Schema::withoutForeignKeyConstraints(fn () => $this->rollback());
    }

    private function rollback(): void
    {
        Schema::dropIfExists('brand_service_candidates');
        Schema::dropIfExists('brand_candidate_resources');
        Schema::dropIfExists('brand_candidates');
        if (Schema::hasColumn('brand_service_areas', 'name')) {
            Schema::table('brand_service_areas', fn (Blueprint $table) => $table->dropColumn('name'));
        }
        if (Schema::hasColumn('brand_offerings', 'locked')) {
            Schema::table('brand_offerings', fn (Blueprint $table) => $table->dropColumn('locked'));
        }
        if (! Schema::hasColumn('resource_automations', 'sector')) {
            Schema::table('resource_automations', fn (Blueprint $table) => $table->string('sector')->nullable());
        }
        if (Schema::hasColumn('brands', 'sector_id')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('sector_id');
            });
        }
    }

    private function backfillBrandSectors(): void
    {
        $categories = DB::table('service_categories')->pluck('id', 'code');
        $pivot = Schema::hasTable('brand_service_category')
            ? DB::table('brand_service_category')->orderBy('created_at')->get(['brand_id', 'service_category_id'])->groupBy('brand_id')
            : collect();
        DB::table('brands')->whereNull('sector_id')->orderBy('id')->get(['id', 'sector'])->each(function (object $brand) use ($categories, $pivot): void {
            $id = $categories[(string) $brand->sector] ?? ($pivot->get($brand->id)?->first()?->service_category_id);
            if ($id !== null) {
                DB::table('brands')->where('id', $brand->id)->update(['sector_id' => $id]);
            }
        });
    }
};
