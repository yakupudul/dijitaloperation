<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('catalog_status', 32)->default('available');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['code' => 'google_ads', 'name' => 'Google Ads Management', 'sort_order' => 10],
            ['code' => 'meta_ads', 'name' => 'Meta Ads Management', 'sort_order' => 20],
            ['code' => 'seo', 'name' => 'SEO', 'sort_order' => 30],
            ['code' => 'content_seo', 'name' => 'Content SEO', 'sort_order' => 40],
            ['code' => 'local_seo', 'name' => 'Local SEO / Google Business Profile', 'sort_order' => 50],
            ['code' => 'website_design', 'name' => 'Website Design / Development', 'sort_order' => 60],
            ['code' => 'website_maintenance', 'name' => 'Website Maintenance', 'sort_order' => 70],
            ['code' => 'analytics', 'name' => 'Analytics & Measurement', 'sort_order' => 80],
            ['code' => 'crm', 'name' => 'CRM', 'sort_order' => 90],
            ['code' => 'marketing_automation', 'name' => 'Marketing Automation', 'sort_order' => 100],
            ['code' => 'strategy', 'name' => 'Digital Strategy / Consulting', 'sort_order' => 110],
            ['code' => 'other', 'name' => 'Other', 'sort_order' => 999],
        ];

        foreach ($rows as $row) {
            DB::table('service_definitions')->insert([
                ...$row,
                'description' => null,
                'catalog_status' => 'available',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_definitions');
    }
};
