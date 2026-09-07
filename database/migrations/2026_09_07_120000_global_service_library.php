<?php

use App\Models\BrandOffering;
use App\Models\ServiceCatalogItem;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Support\Options\IndustryOptions;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_catalog_items', function (Blueprint $table): void {
            $table->softDeletesTz();
        });
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 120);
            $table->string('normalized_key', 255)->unique();
            $table->timestamps();
        });
        $labels = [
            'healthcare' => 'Sağlık', 'dental' => 'Diş sağlığı',
            'medical_aesthetics' => 'Medikal estetik ve plastik cerrahi', 'beauty' => 'Güzellik ve kişisel bakım',
            'retail' => 'Perakende', 'ecommerce' => 'E-ticaret', 'professional_services' => 'Profesyonel hizmetler',
            'real_estate' => 'Gayrimenkul', 'hospitality' => 'Turizm ve konaklama', 'fitness' => 'Spor ve sağlıklı yaşam',
            'education' => 'Eğitim', 'technology' => 'Teknoloji', 'automotive' => 'Otomotiv',
            'food_beverage' => 'Yiyecek ve içecek', 'finance' => 'Finans', 'legal' => 'Hukuk',
            'manufacturing' => 'Üretim', 'construction' => 'İnşaat', 'media' => 'Medya ve eğlence',
            'nonprofit' => 'Sivil toplum', 'other' => 'Diğer',
        ];
        foreach (DB::table('service_catalog_items')->whereNotNull('sector')->distinct()->pluck('sector') as $code) {
            if ($code !== '') {
                $labels[$code] ??= IndustryOptions::defaults()[$code] ?? $code;
            }
        }
        $usedNames = [];
        foreach ($labels as $code => $name) {
            $normalized = app(IdentityLabelNormalizer::class)->normalize($name);
            if (isset($usedNames[$normalized])) {
                $name = mb_substr($name, 0, 75).' ('.mb_substr($code, 0, 40).')';
                $normalized = app(IdentityLabelNormalizer::class)->normalize($name);
            }
            $usedNames[$normalized] = true;
            DB::table('service_categories')->insert([
                'code' => $code, 'name' => $name,
                'normalized_key' => $normalized,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Preserve brand IDs and their goal/query relationships while attaching legacy services.
        BrandOffering::query()->with(['primaryName', 'brand'])->whereNull('service_catalog_item_id')
            ->chunkById(100, function ($offerings): void {
                foreach ($offerings as $offering) {
                    $label = $offering->primaryName?->raw_label;
                    if (! is_string($label) || trim($label) === '') {
                        continue;
                    }
                    $service = app(ServiceCatalogService::class)->resolveOrCreate($label, $offering->brand?->sector)['service'];
                    $conflict = BrandOffering::query()->where('brand_id', $offering->brand_id)
                        ->where('service_catalog_item_id', $service->id)->where('id', '!=', $offering->id)->exists();
                    if ($conflict) {
                        throw new RuntimeException('Global service backfill conflict: brand offering '.$offering->id.'. Existing brand relationships were not merged.');
                    }
                    $offering->update(['service_catalog_item_id' => $service->id]);
                }
            });
        ServiceCatalogItem::query()->with('primaryName')->chunkById(100, function ($services): void {
            foreach ($services as $service) {
                $label = $service->primaryName?->raw_label;
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }
                foreach ($service->brandOfferings()->get() as $offering) {
                    app(BrandOfferingService::class)->renameLocal($offering, $label);
                }
                app(ServiceCatalogService::class)->refreshBrandContexts($service);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
        Schema::table('service_catalog_items', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
