<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uzmanlar (yakup, 2026-10-07, AI görünürlüğü 3): the brand's experts (doctor, specialist) an article is published
 * under. `wp_author` is their WordPress user (login or e-mail): the draft goes out with that author, so the SEO plugin
 * prints the author's Person schema and profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_experts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('title', 160)->nullable();
            $table->string('wp_author', 160)->nullable();
            $table->string('profile_url', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('source', 16)->default('operator'); // operator | auto
            $table->timestampsTz();
            $table->index(['brand_id', 'is_default'], 'brand_experts_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_experts');
    }
};
