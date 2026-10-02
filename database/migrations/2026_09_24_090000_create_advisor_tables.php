<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel advisor (Faz 3+): one plan run per digital asset and channel, and the few items it keeps open.
 * Same lifecycle as SEO Görevleri: stable item_key, done/skipped kept, items no longer produced close themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 24); // google_ads (meta_ads, gbp later)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('status', 24)->default('queued'); // queued|running|completed|failed
            $table->string('trigger', 24)->default('manual'); // manual|scheduled|bulk
            $table->unsignedInteger('version')->default(1);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('input_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('summary_text')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'channel', 'status', 'id'], 'advisor_plans_asset_status_idx');
        });

        Schema::create('advisor_items', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 24);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->char('item_key', 64);
            $table->string('category', 32); // waste|growth|measurement|landing|ads|quality|change
            $table->string('rule_id', 96);
            $table->string('severity', 16)->default('medium'); // critical|high|medium|low
            $table->decimal('priority_score', 12, 2)->default(0);
            $table->decimal('impact_amount', 14, 2)->nullable(); // money at stake in the account currency
            $table->string('impact_label', 160)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('title', 255);
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->json('checklist')->nullable();
            $table->text('copy_text')->nullable(); // paste-ready block (negative list, keywords)
            $table->json('baseline')->nullable(); // metrics when produced; later outcome measurement
            $table->json('draft')->nullable(); // AI draft made on operator click
            $table->string('draft_status', 16)->nullable(); // queued|ready|failed
            $table->string('status', 16)->default('open'); // open|done|skipped|resolved
            $table->foreignId('first_seen_plan_id')->constrained('advisor_plans')->cascadeOnDelete();
            $table->foreignId('last_seen_plan_id')->constrained('advisor_plans')->cascadeOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'channel', 'item_key'], 'advisor_items_asset_key_uq');
            $table->index(['channel', 'status', 'priority_score'], 'advisor_items_channel_status_idx');
            $table->index(['customer_id', 'status'], 'advisor_items_customer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_items');
        Schema::dropIfExists('advisor_plans');
    }
};
