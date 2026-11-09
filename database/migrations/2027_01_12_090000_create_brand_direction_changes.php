<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marka rotası (yakup 2026-11-09: "markanın rotası değiştiğinde diğer çalışan şeyler değişmiyor"): one row each time a
 * brand's direction (active services, ★ services, summary, positioning, audiences) is seen to change, with what changed,
 * how big the change was and what the realignment did (cluster rows set aside, content ideas closed, demand asked for).
 * The first row of a brand is its baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_direction_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('magnitude', 12); // baseline | minor | major
            $table->string('trigger', 12)->default('auto'); // auto | operator | system
            $table->string('reason', 500)->nullable();
            $table->json('snapshot')->nullable();
            $table->json('added')->nullable();
            $table->json('removed')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['brand_id', 'id'], 'brand_direction_changes_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_direction_changes');
    }
};
