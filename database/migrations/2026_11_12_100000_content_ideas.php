<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik havuzu (docs/product/CONTENT_IDEAS_BLUEPRINT.md §4). `content_ideas`: system-wide extra content ideas of a
 * cluster (the cluster itself is its main idea), produced only when the operator asks ("Yeni fikir üret"), with the
 * brand it was produced for. `brand_content_ideas`: how one brand's website answers an idea (page, state) — the
 * "used by" brands of the pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_ideas')) {
            Schema::create('content_ideas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
                $table->string('title', 200);
                $table->string('title_key', 200);
                $table->string('type', 16); // service | guide | faq | comparison | location
                $table->string('angle', 400)->nullable();
                $table->json('target_queries')->nullable(); // [{text, in_cluster}]
                $table->json('outline')->nullable(); // H2 list
                $table->foreignId('origin_brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('prompt_version_id')->nullable();
                $table->string('status', 12)->default('active'); // active | archived
                $table->timestampsTz();
                $table->unique(['cluster_id', 'title_key'], 'content_ideas_cluster_title_uq');
            });
        }
        if (! Schema::hasTable('brand_content_ideas')) {
            Schema::create('brand_content_ideas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('content_idea_id')->constrained('content_ideas')->cascadeOnDelete();
                $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->string('state', 16)->nullable(); // sufficient | improve | no_page | technical
                $table->string('coverage', 10)->nullable();
                $table->json('gaps')->nullable();
                $table->string('reason', 500)->nullable();
                $table->boolean('locked')->default(false);
                $table->timestampTz('audited_at')->nullable();
                $table->timestampsTz();
                $table->unique(['brand_id', 'content_idea_id', 'website_asset_id'], 'brand_content_ideas_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_content_ideas');
        Schema::dropIfExists('content_ideas');
    }
};
