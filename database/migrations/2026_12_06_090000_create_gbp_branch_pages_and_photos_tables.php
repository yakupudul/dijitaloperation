<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşletme profilleri masası (ADR-079): the branch page prepared for each Business Profile (AI text sent to WordPress
 * as a draft page) and the photos added to profiles (from the brand's site or uploaded in MoxDOP).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gbp_branch_pages')) {
            Schema::create('gbp_branch_pages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->unsignedBigInteger('website_asset_id')->nullable();
                $table->string('status', 16)->default('ready'); // ready | sent | failed
                $table->json('content')->nullable();
                $table->json('issues')->nullable();
                $table->unsignedBigInteger('prompt_version_id')->nullable();
                $table->unsignedBigInteger('draft_action_id')->nullable();
                $table->unsignedBigInteger('wp_post_id')->nullable();
                $table->text('edit_url')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('gbp_photos')) {
            Schema::create('gbp_photos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->text('source_url');
                $table->char('source_hash', 64);
                $table->string('source', 8)->default('site'); // site | upload
                $table->string('category', 16)->default('ADDITIONAL');
                $table->string('title', 200)->nullable();
                $table->string('status', 16)->default('sending'); // sending | uploaded | failed | removed
                $table->unsignedBigInteger('external_write_action_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'source_hash']);
                $table->index(['brand_id', 'source_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_photos');
        Schema::dropIfExists('gbp_branch_pages');
    }
};
