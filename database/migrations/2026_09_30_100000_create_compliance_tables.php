<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sector packs and compliance. A pack (code, e.g. health) ships default rules; the owner can switch the pack
 * off, deactivate or edit rules and add own rules (origin = operator; never overwritten). Findings are what
 * the auditor found in AI drafts, live ads, website pages and Business Profile content, with the evidence
 * copied (GBP content is purged after 30 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_pack_settings', function (Blueprint $table): void {
            $table->string('pack_id', 40)->primary();
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('pack_id', 40);
            $table->string('rule_key', 80);
            $table->string('kind', 30);
            $table->string('label', 160);
            $table->json('patterns');
            $table->text('message');
            $table->string('severity', 10)->default('medium');
            $table->json('applies_to')->nullable();
            $table->boolean('active')->default(true);
            $table->string('origin', 20)->default('pack');
            $table->timestamps();

            $table->unique(['pack_id', 'rule_key']);
        });

        Schema::create('compliance_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->foreignId('compliance_rule_id')->constrained('compliance_rules')->cascadeOnDelete();
            $table->string('source', 30);
            $table->string('subject_ref', 255);
            $table->string('subject_label', 255)->nullable();
            $table->string('matched', 255);
            $table->text('excerpt');
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 20)->default('open');
            $table->string('note', 500)->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_findings');
        Schema::dropIfExists('compliance_rules');
        Schema::dropIfExists('sector_pack_settings');
    }
};
