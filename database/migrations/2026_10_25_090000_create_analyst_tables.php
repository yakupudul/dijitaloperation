<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 — AI analyst engine (brand workspace).
 *
 *  - analyst_runs: one weekly / on-demand analysis of one brand × channel (search, maps, google_ads, meta): pack hash,
 *    token / cost, status (queued | running | done | skipped | failed) and the one-line reason when skipped / failed.
 *  - analyst_decisions: the AI decisions that passed validation, persisted by fingerprint across runs. Done /
 *    dismissed decisions do not come back unless their action materially changes; snoozed ones return after the date.
 *    `evidence` keeps the pack facts behind the card (Kanıt), `baseline` the numbers when the operator marked it done
 *    (outcome follow-up), `outcome` is filled by a later measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyst_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('status', 16)->default('queued');
            $table->string('trigger', 16)->default('manual'); // manual | weekly
            $table->char('pack_hash', 64)->nullable();
            $table->unsignedInteger('pack_tokens')->nullable();
            $table->json('stats')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->string('provider', 48)->nullable();
            $table->string('model', 190)->nullable();
            $table->unsignedSmallInteger('decisions_received')->default(0);
            $table->unsignedSmallInteger('decisions_kept')->default(0);
            $table->json('dropped')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['brand_id', 'channel', 'id'], 'analyst_runs_brand_channel_idx');
        });

        Schema::create('analyst_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->foreignId('analyst_run_id')->nullable()->constrained('analyst_runs')->nullOnDelete();
            $table->string('decision_key', 160);
            $table->char('fingerprint', 64);
            $table->char('material_hash', 64);
            $table->string('title', 160);
            $table->string('why', 240);
            $table->unsignedTinyInteger('priority')->default(3);
            $table->json('impact')->nullable();
            $table->string('effort', 16)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('evidence')->nullable();
            $table->string('action_type', 48);
            $table->json('action_params')->nullable();
            $table->string('status', 16)->default('open'); // open | done | dismissed | snoozed | expired
            $table->timestampTz('snoozed_until')->nullable();
            $table->text('operator_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->json('baseline')->nullable();
            $table->json('outcome')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'channel', 'fingerprint'], 'analyst_decisions_fingerprint_uq');
            $table->index(['brand_id', 'status', 'priority'], 'analyst_decisions_brand_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyst_decisions');
        Schema::dropIfExists('analyst_runs');
    }
};
