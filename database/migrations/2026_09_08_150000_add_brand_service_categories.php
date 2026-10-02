<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_service_category', function (Blueprint $table): void {
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['brand_id', 'service_category_id']);
        });

        $categories = DB::table('service_categories')->pluck('id', 'code');
        DB::table('brands')->orderBy('id')->chunkById(100, function ($brands) use ($categories): void {
            $services = DB::table('brand_offerings')
                ->join('service_catalog_items', 'service_catalog_items.id', '=', 'brand_offerings.service_catalog_item_id')
                ->whereIn('brand_offerings.brand_id', $brands->pluck('id'))
                ->where('brand_offerings.status', 'active')
                ->where('service_catalog_items.status', 'active')
                ->whereNull('service_catalog_items.deleted_at')
                ->select('brand_offerings.brand_id', 'service_catalog_items.sector')
                ->get()->groupBy('brand_id');
            foreach ($brands as $brand) {
                $codes = collect([$brand->sector])
                    ->merge(($services->get($brand->id) ?? collect())->pluck('sector'))
                    ->filter()->unique();
                foreach ($codes as $code) {
                    if ($categories->has($code)) {
                        DB::table('brand_service_category')->insertOrIgnore([
                            'brand_id' => $brand->id,
                            'service_category_id' => $categories->get($code),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_service_category');
    }
};
