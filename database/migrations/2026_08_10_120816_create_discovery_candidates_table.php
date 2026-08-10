<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single candidate-review table for Discovery Intelligence V1 (Accept / Edit / Ignore).
 * No Discovery / Result / Competitor domain tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $table->string('fingerprint')->index();
            $table->string('candidate_kind'); // fact | inference
            $table->string('candidate_type'); // service, location, language, social_link, business_summary, positioning, differentiator, competitor, audience, conflict, ...
            $table->string('target_field'); // Brand Context / asset field key or discovery-only key
            $table->text('proposed_value');
            $table->json('support_json')->nullable();
            $table->string('support_label')->nullable(); // strong | moderate | weak
            $table->string('status')->default('pending')->index(); // pending | accepted | ignored
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('accepted_value')->nullable();
            $table->boolean('was_edited')->default(false);
            $table->timestamps();

            $table->unique(['digital_asset_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_candidates');
    }
};
