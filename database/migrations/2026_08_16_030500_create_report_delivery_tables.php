<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 60 — PDF artifacts, authenticated share, delivery, report-specific schedules.
 * No generic automation / CRM / public share tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_artifacts')) {
            Schema::create('report_artifacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_snapshot_id')->constrained('report_snapshots')->restrictOnDelete();
                $table->string('artifact_type', 16);
                $table->string('snapshot_schema_version', 64);
                $table->string('renderer_version', 64);
                $table->string('content_checksum', 64);
                $table->string('file_checksum', 64);
                $table->string('storage_disk', 64);
                $table->string('storage_path', 512);
                $table->string('mime_type', 64);
                $table->unsignedBigInteger('byte_size');
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at');
                $table->string('idempotency_key', 128)->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['report_snapshot_id', 'renderer_version'], 'report_artifacts_snapshot_renderer_uq');
                $table->index(['report_snapshot_id', 'generated_at']);
            });
        }

        if (! Schema::hasTable('report_share_grants')) {
            Schema::create('report_share_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_snapshot_id')->constrained('report_snapshots')->restrictOnDelete();
                $table->string('recipient_email', 255);
                $table->string('recipient_name', 255)->nullable();
                $table->json('permissions');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('locator_token_hash', 128);
                $table->timestamp('last_successful_access_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('locator_token_hash');
                $table->index(['report_snapshot_id', 'expires_at']);
                $table->index(['recipient_email', 'expires_at']);
                $table->index('revoked_at');
            });
        }

        if (! Schema::hasTable('report_share_verification_challenges')) {
            Schema::create('report_share_verification_challenges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('share_grant_id')->constrained('report_share_grants')->cascadeOnDelete();
                $table->string('code_hash', 128);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['share_grant_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('report_share_sessions')) {
            Schema::create('report_share_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('share_grant_id')->constrained('report_share_grants')->cascadeOnDelete();
                $table->string('session_token_hash', 128);
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('session_token_hash');
                $table->index(['share_grant_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('report_share_access_events')) {
            Schema::create('report_share_access_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('share_grant_id')->constrained('report_share_grants')->cascadeOnDelete();
                $table->string('event_type', 64);
                $table->unsignedBigInteger('share_session_id')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('user_agent_hash', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['share_grant_id', 'created_at']);
                $table->index('event_type');
            });
        }

        if (! Schema::hasTable('report_delivery_schedules')) {
            Schema::create('report_delivery_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->string('report_type', 64);
                $table->string('locale', 16);
                $table->string('timezone', 64);
                $table->string('cadence', 32);
                $table->unsignedTinyInteger('day_of_month');
                $table->time('delivery_time');
                $table->string('period_strategy', 64);
                $table->unsignedInteger('share_ttl_hours');
                $table->string('status', 32);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['brand_id', 'status']);
                $table->index(['customer_id', 'status']);
                $table->index(['status', 'cadence']);
            });
        }

        if (! Schema::hasTable('report_delivery_schedule_recipients')) {
            Schema::create('report_delivery_schedule_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('report_delivery_schedules')->cascadeOnDelete();
                $table->string('email', 255);
                $table->string('display_name', 255)->nullable();
                $table->string('locale_override', 16)->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->unique(['schedule_id', 'email']);
            });
        }

        if (! Schema::hasTable('report_delivery_occurrences')) {
            Schema::create('report_delivery_occurrences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('report_delivery_schedules')->restrictOnDelete();
                $table->timestamp('scheduled_for');
                $table->date('period_start');
                $table->date('period_end');
                $table->foreignId('report_snapshot_id')->nullable()->constrained('report_snapshots')->nullOnDelete();
                $table->foreignId('artifact_id')->nullable()->constrained('report_artifacts')->nullOnDelete();
                $table->string('status', 32);
                $table->string('occurrence_key', 191);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('failure_category', 64)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->timestamps();

                $table->unique('occurrence_key');
                $table->index(['schedule_id', 'scheduled_for']);
                $table->index(['status', 'scheduled_for']);
            });
        }

        if (! Schema::hasTable('report_deliveries')) {
            Schema::create('report_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_snapshot_id')->constrained('report_snapshots')->restrictOnDelete();
                $table->string('recipient_email_snapshot', 255);
                $table->string('recipient_name_snapshot', 255)->nullable();
                $table->string('delivery_mode', 64);
                $table->foreignId('share_grant_id')->nullable()->constrained('report_share_grants')->nullOnDelete();
                $table->foreignId('artifact_id')->nullable()->constrained('report_artifacts')->nullOnDelete();
                $table->string('locale', 16);
                $table->string('subject_template_version', 64);
                $table->string('email_template_version', 64);
                $table->string('status', 32);
                $table->foreignId('schedule_occurrence_id')->nullable()->constrained('report_delivery_occurrences')->nullOnDelete();
                $table->string('idempotency_key', 191)->nullable()->unique();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_category', 64)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['report_snapshot_id', 'created_at']);
                $table->index(['status', 'created_at']);
                $table->unique(['schedule_occurrence_id', 'recipient_email_snapshot'], 'report_deliveries_occurrence_recipient_uq');
            });
        }

        if (! Schema::hasTable('report_delivery_attempts')) {
            Schema::create('report_delivery_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('delivery_id')->constrained('report_deliveries')->cascadeOnDelete();
                $table->unsignedSmallInteger('attempt_number');
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->string('result', 32);
                $table->string('transport_message_id', 191)->nullable();
                $table->string('error_class', 64)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['delivery_id', 'attempt_number']);
                $table->index(['delivery_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_delivery_attempts');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_delivery_occurrences');
        Schema::dropIfExists('report_delivery_schedule_recipients');
        Schema::dropIfExists('report_delivery_schedules');
        Schema::dropIfExists('report_share_access_events');
        Schema::dropIfExists('report_share_sessions');
        Schema::dropIfExists('report_share_verification_challenges');
        Schema::dropIfExists('report_share_grants');
        Schema::dropIfExists('report_artifacts');
    }
};
