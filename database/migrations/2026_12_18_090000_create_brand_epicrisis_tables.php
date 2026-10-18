<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Marka epikrizi (yakup, 2026-10-16 "AI yazıp doğrudan kullansın, bu şekilde yap"): one verified record per brand that
 * every AI step reads.
 *
 *  - brand_epicrisis_items: one row per fact, evidence line, synthesis line, lesson or open question, with its source,
 *    confidence (verified / data / inferred), state (active, conflict, expired, dropped; open / answered / dismissed for
 *    questions), whether the operator locked it, when it expires and how often AI work used it.
 *  - brand_epicrises: per brand the one-page summary every AI step reads, how full the record is and when its layers
 *    were last built.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_epicrisis_items')) {
            Schema::create('brand_epicrisis_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->string('layer', 12);
                $table->string('field', 24);
                $table->string('scope', 160)->nullable();
                $table->text('value');
                $table->json('detail')->nullable();
                $table->string('source_kind', 16);
                $table->string('source_ref', 400)->nullable();
                $table->string('source_text', 400)->nullable();
                $table->string('confidence', 10)->default('data');
                $table->string('status', 10)->default('active');
                $table->boolean('locked')->default(false);
                $table->string('fingerprint', 64);
                $table->timestamp('seen_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedInteger('used_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedBigInteger('answered_by')->nullable();
                $table->timestamps();
                $table->unique(['brand_id', 'fingerprint']);
                $table->index(['brand_id', 'layer', 'status']);
            });
        }
        if (! Schema::hasTable('brand_epicrises')) {
            Schema::create('brand_epicrises', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
                $table->text('summary')->nullable();
                $table->string('summary_hash', 64)->nullable();
                $table->unsignedTinyInteger('fill_percent')->default(0);
                $table->json('counts')->nullable();
                $table->string('facts_basis', 64)->nullable();
                $table->string('synthesis_basis', 64)->nullable();
                $table->json('synthesis_keys')->nullable();
                $table->timestamp('built_at')->nullable();
                $table->timestamp('facts_read_at')->nullable();
                $table->timestamp('synthesized_at')->nullable();
                $table->timestamp('lessons_at')->nullable();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['brand.epicrisis_facts', 'brand.epicrisis_synthesis']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_epicrises');
        Schema::dropIfExists('brand_epicrisis_items');
    }
};
