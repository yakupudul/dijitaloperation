<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 61 — shared recurring automation engine core tables.
 * Domain adapters (collection / RR / BO recheck / internal notify / report delivery) bind later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurring_occurrences')) {
            Schema::create('recurring_occurrences', function (Blueprint $table): void {
                $table->id();
                $table->string('schedule_kind', 64);
                $table->unsignedBigInteger('domain_schedule_id');
                $table->timestamp('scheduled_for');
                $table->string('timezone_snapshot', 64);
                $table->string('recurrence_spec_fingerprint', 64);
                $table->string('status', 32);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->string('domain_run_type', 64)->nullable();
                $table->unsignedBigInteger('domain_run_id')->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->boolean('is_manual')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->string('occurrence_key', 191);

                $table->unique('occurrence_key');
                $table->unique(
                    ['schedule_kind', 'domain_schedule_id', 'scheduled_for'],
                    'recurring_occurrences_kind_schedule_time_uq'
                );
                $table->index('status');
                $table->index(['schedule_kind', 'status']);
                $table->index('scheduled_for');
            });
        }

        if (! Schema::hasTable('collection_schedules')) {
            Schema::create('collection_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->restrictOnDelete();
                $table->string('frequency', 32);
                $table->unsignedInteger('interval')->default(1);
                $table->string('timezone', 64);
                $table->time('local_time');
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->json('weekdays')->nullable();
                $table->string('misfire_policy', 32);
                $table->string('status', 32);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->timestamp('next_run_at')->nullable();

                $table->index(['customer_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index(['digital_asset_id', 'status']);
                $table->index(['status', 'next_run_at']);
            });
        }

        if (! Schema::hasTable('business_outcome_recheck_schedules')) {
            Schema::create('business_outcome_recheck_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->string('locale', 16)->default('en');
                $table->string('timezone', 64);
                $table->string('frequency', 32);
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->json('weekdays')->nullable();
                $table->time('delivery_time');
                $table->string('period_strategy', 64);
                $table->string('misfire_policy', 32);
                $table->string('status', 32);
                $table->boolean('attention_on_no_data')->default(true);
                $table->boolean('attention_on_partial')->default(true);
                $table->boolean('attention_on_unknown')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index(['status', 'frequency']);
            });
        }

        if (! Schema::hasTable('business_outcome_recheck_schedule_recipients')) {
            Schema::create('business_outcome_recheck_schedule_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('business_outcome_recheck_schedules')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['schedule_id', 'user_id'], 'bo_recheck_schedule_recipients_uq');
            });
        }

        if (! Schema::hasTable('business_outcome_recheck_runs')) {
            Schema::create('business_outcome_recheck_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('business_outcome_recheck_schedules')->cascadeOnDelete();
                $table->foreignId('recurring_occurrence_id')->nullable()->unique()->constrained('recurring_occurrences')->nullOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 32);
                $table->json('results_payload')->nullable();
                $table->boolean('notified')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();

                $table->index(['schedule_id', 'period_start']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('internal_notification_schedules')) {
            Schema::create('internal_notification_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('timezone', 64);
                $table->string('frequency', 32);
                $table->unsignedInteger('interval')->default(1);
                $table->time('local_time');
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->json('weekdays')->nullable();
                $table->string('title');
                $table->text('message');
                $table->string('safe_route_name')->nullable();
                $table->string('misfire_policy', 32);
                $table->string('status', 32);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index(['status', 'frequency']);
            });
        }

        if (! Schema::hasTable('internal_notification_schedule_recipients')) {
            Schema::create('internal_notification_schedule_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('internal_notification_schedules')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['schedule_id', 'user_id'], 'internal_notification_schedule_recipients_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notification_schedule_recipients');
        Schema::dropIfExists('internal_notification_schedules');
        Schema::dropIfExists('business_outcome_recheck_runs');
        Schema::dropIfExists('business_outcome_recheck_schedule_recipients');
        Schema::dropIfExists('business_outcome_recheck_schedules');
        Schema::dropIfExists('collection_schedules');
        Schema::dropIfExists('recurring_occurrences');
    }
};
