<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_search_profiles', function (Blueprint $table): void {
            $table->foreignId('service_catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('free_radar_enabled')->default(false);
            $table->unsignedInteger('radar_interval_minutes')->default(60);
            $table->timestamp('radar_next_at')->nullable()->index();
        });
        Schema::create('sales_radar_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('url_hash', 64)->unique();
            $table->string('format')->default('html');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_at')->nullable();
            $table->string('state')->default('not_checked');
            $table->string('error')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
        });
        Schema::create('sales_radar_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_radar_source_id')->constrained()->cascadeOnDelete();
            $table->string('url', 255);
            $table->string('url_hash', 64)->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('state')->default('pending');
            $table->string('author')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['sales_radar_source_id', 'state']);
        });
        foreach ([
            ['WM Aracı · İş verenler', 'https://wmaraci.com/forum/is-verenler.html'],
            ['WM Aracı · Google Ads', 'https://wmaraci.com/forum/adwords.html'],
            ['WM Aracı · SEO', 'https://wmaraci.com/forum/seo.html'],
            ['R10 · Yazılım ve web sitesi iş verenler', 'https://www.r10.net/yazilim-kodlama-is-verenler/'],
        ] as [$name, $url]) {
            DB::table('sales_radar_sources')->insert([
                'name' => $name, 'url' => $url, 'url_hash' => hash('sha256', $url),
                'format' => 'html', 'enabled' => true, 'interval_minutes' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_radar_pages');
        Schema::dropIfExists('sales_radar_sources');
        Schema::table('sales_search_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_catalog_item_id');
            $table->dropColumn(['free_radar_enabled', 'radar_interval_minutes', 'radar_next_at']);
        });
    }
};
