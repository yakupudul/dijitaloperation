<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sector', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'sector'], 'service_catalog_status_sector_idx');
        });

        Schema::create('service_catalog_names', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_catalog_item_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $table->string('raw_label');
            $table->string('normalized_key');
            $table->string('locale', 32)->nullable();
            $table->string('name_kind', 32)->default('alias');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('provenance', 48)->default('operator');
            $table->string('normalization_version', 32);
            $table->timestampsTz();

            $table->unique('normalized_key', 'service_catalog_name_normalized_uq');
            $table->index(['service_catalog_item_id', 'is_primary'], 'service_catalog_name_primary_idx');
        });

        Schema::table('brand_offerings', function (Blueprint $table): void {
            $table->foreignId('service_catalog_item_id')
                ->nullable()
                ->after('brand_id')
                ->constrained('service_catalog_items')
                ->nullOnDelete();
            $table->unique(
                ['brand_id', 'service_catalog_item_id'],
                'brand_offering_catalog_item_uq',
            );
        });

        Schema::create('brand_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('country_code', 8);
            $table->string('country_name', 120)->nullable();
            $table->string('city_name', 160)->nullable();
            $table->string('district_name', 160)->nullable();
            $table->char('normalized_key', 64);
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('priority_rank')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'normalized_key'], 'brand_service_area_identity_uq');
            $table->index(['brand_id', 'status', 'priority_rank'], 'brand_service_area_status_idx');
        });

        $this->backfillExistingBrandContext();

        Schema::create('search_query_library_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type', 48);
            $table->string('original_filename')->nullable();
            $table->string('status', 24)->default('processing');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->text('error_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['source_type', 'status'], 'query_library_import_source_status_idx');
        });

        Schema::create('search_query_library_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('identity_hash', 64)->unique();
            $table->text('canonical_text');
            $table->text('folded_text');
            $table->string('language_code', 32)->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('market_code', 32)->nullable();
            $table->string('sector', 120)->nullable();
            $table->string('demand_family')->nullable();
            $table->string('location_scope', 32)->default('none');
            $table->string('location_value')->nullable();
            $table->boolean('is_branded')->default(false);
            $table->string('status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->string('normalization_version', 32);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'language_code', 'market_code'], 'query_library_status_market_idx');
            $table->index(['sector', 'demand_family'], 'query_library_sector_family_idx');
            $table->index(['is_branded', 'status'], 'query_library_branded_status_idx');
        });

        Schema::create('search_query_library_item_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_query_library_item_id')->constrained('search_query_library_items')->cascadeOnDelete();
            $table->foreignId('service_catalog_item_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('provenance', 48)->default('operator');
            $table->timestampsTz();

            $table->unique(
                ['search_query_library_item_id', 'service_catalog_item_id'],
                'query_library_item_service_uq',
            );
            $table->index(['service_catalog_item_id', 'is_primary'], 'query_library_service_primary_idx');
        });

        Schema::create('search_query_library_source_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_query_library_item_id')->constrained('search_query_library_items')->cascadeOnDelete();
            $table->foreignId('search_query_library_import_id')->nullable()->constrained('search_query_library_imports')->nullOnDelete();
            $table->foreignId('service_catalog_item_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->char('source_fingerprint', 64)->unique();
            $table->string('source_type', 48);
            $table->string('source_reference')->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->text('observed_text');
            $table->string('country_code', 8)->nullable();
            $table->string('city_name', 160)->nullable();
            $table->string('district_name', 160)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('impressions', 20, 4)->nullable();
            $table->decimal('clicks', 20, 4)->nullable();
            $table->decimal('conversions', 20, 4)->nullable();
            $table->decimal('cost', 20, 6)->nullable();
            $table->decimal('search_volume', 20, 4)->nullable();
            $table->decimal('cpc', 20, 6)->nullable();
            $table->decimal('competition', 12, 6)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();

            $table->index(['source_type', 'observed_at'], 'query_library_source_observed_idx');
            $table->index(['search_query_library_item_id', 'source_type'], 'query_library_item_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_library_source_records');
        Schema::dropIfExists('search_query_library_item_service');
        Schema::dropIfExists('search_query_library_items');
        Schema::dropIfExists('search_query_library_imports');
        Schema::dropIfExists('brand_service_areas');

        Schema::table('brand_offerings', function (Blueprint $table): void {
            $table->dropUnique('brand_offering_catalog_item_uq');
            $table->dropConstrainedForeignId('service_catalog_item_id');
        });

        Schema::dropIfExists('service_catalog_names');
        Schema::dropIfExists('service_catalog_items');
    }

    private function backfillExistingBrandContext(): void
    {
        $now = now();
        $serviceIds = [];

        $offerings = DB::table('brand_offerings')
            ->join('brand_offering_names', function ($join): void {
                $join->on('brand_offering_names.brand_offering_id', '=', 'brand_offerings.id')
                    ->where('brand_offering_names.is_primary', true)
                    ->where('brand_offering_names.is_active', true);
            })
            ->join('brands', 'brands.id', '=', 'brand_offerings.brand_id')
            ->select([
                'brand_offerings.id as offering_id',
                'brand_offering_names.raw_label',
                'brands.sector',
            ])
            ->orderBy('brand_offerings.id')
            ->get();

        foreach ($offerings as $offering) {
            $label = trim((string) $offering->raw_label);
            $normalized = $this->normalizeLabel($label);
            if ($normalized === '') {
                continue;
            }

            if (! isset($serviceIds[$normalized])) {
                $existing = DB::table('service_catalog_names')
                    ->where('normalized_key', $normalized)
                    ->value('service_catalog_item_id');

                if ($existing !== null) {
                    $serviceIds[$normalized] = (int) $existing;
                } else {
                    $serviceId = DB::table('service_catalog_items')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'sector' => $offering->sector,
                        'description' => null,
                        'status' => 'active',
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('service_catalog_names')->insert([
                        'service_catalog_item_id' => $serviceId,
                        'raw_label' => $label,
                        'normalized_key' => $normalized,
                        'locale' => null,
                        'name_kind' => 'primary',
                        'is_primary' => true,
                        'is_active' => true,
                        'provenance' => 'legacy_backfill',
                        'normalization_version' => 'v1',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $serviceIds[$normalized] = (int) $serviceId;
                }
            }

            DB::table('brand_offerings')
                ->where('id', $offering->offering_id)
                ->whereNull('service_catalog_item_id')
                ->update(['service_catalog_item_id' => $serviceIds[$normalized], 'updated_at' => $now]);
        }

        $brands = DB::table('brands')->select(['id', 'primary_country', 'target_markets'])->orderBy('id')->get();
        foreach ($brands as $brand) {
            $countries = [];
            $primary = strtoupper(trim((string) $brand->primary_country));
            if ($primary !== '') {
                $countries[] = $primary;
            }
            $targets = is_string($brand->target_markets) ? json_decode($brand->target_markets, true) : $brand->target_markets;
            if (is_array($targets)) {
                foreach ($targets as $target) {
                    if (is_string($target) && preg_match('/^[A-Za-z]{2,8}$/', trim($target))) {
                        $countries[] = strtoupper(trim($target));
                    }
                }
            }

            foreach (array_values(array_unique($countries)) as $rank => $countryCode) {
                $key = hash('sha256', mb_strtolower($countryCode.'||', 'UTF-8'));
                DB::table('brand_service_areas')->insertOrIgnore([
                    'brand_id' => $brand->id,
                    'country_code' => $countryCode,
                    'country_name' => $countryCode,
                    'city_name' => null,
                    'district_name' => null,
                    'normalized_key' => $key,
                    'status' => 'active',
                    'priority_rank' => $rank + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function normalizeLabel(string $label): string
    {
        $value = class_exists(\Normalizer::class) ? \Normalizer::normalize($label, \Normalizer::FORM_C) : $label;
        $value = is_string($value) ? $value : $label;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = mb_strtolower($value, 'UTF-8');

        return str_replace("i\u{0307}", 'i', $value);
    }
};
