<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_matching_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('normalized_key');
            $table->timestamps();
            $table->unique(['service_catalog_item_id', 'normalized_key'], 'service_keyword_unique');
        });
        Schema::create('search_query_library_sectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_query_library_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['search_query_library_item_id', 'service_category_id'], 'query_sector_unique');
        });
        Schema::table('search_query_library_imports', function (Blueprint $table): void {
            $table->json('input_payload')->nullable();
        });
        DB::table('search_query_library_items')->whereNotNull('sector')->orderBy('id')->chunkById(500, function ($items): void {
            $categories = DB::table('service_categories')->pluck('id', 'code');
            foreach ($items as $item) {
                if (isset($categories[$item->sector])) {
                    DB::table('search_query_library_sectors')->insertOrIgnore([
                        'search_query_library_item_id' => $item->id, 'service_category_id' => $categories[$item->sector],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('search_query_library_imports', fn (Blueprint $table) => $table->dropColumn('input_payload'));
        Schema::dropIfExists('search_query_library_sectors');
        Schema::dropIfExists('service_matching_keywords');
    }
};
