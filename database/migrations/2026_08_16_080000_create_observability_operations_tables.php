<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 66 — durable operational alerts, worker heartbeats, provider API counters.
 * No health-score tables. No secret columns. No EAV metrics megatable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('semantic_key', 191)->unique();
            $table->string('rule_key', 80);
            $table->unsignedInteger('rule_version');
            $table->string('rule_type', 64);
            $table->string('signal_family', 40);
            $table->string('severity', 20);
            $table->string('state', 20)->index();
            $table->string('scope_type', 40);
            $table->string('scope_key', 120);
            $table->string('title', 255);
            $table->string('summary', 500)->nullable();
            $table->json('observed')->nullable();
            $table->unsignedInteger('observation_count')->default(1);
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('opened_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ack_note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_kind', 40)->nullable(); // RECOVERED | CLOSED_BY_OPERATOR
            $table->boolean('notification_emitted')->default(false);
            $table->timestamps();

            $table->index(['state', 'rule_key', 'scope_key'], 'ops_alerts_active_lookup_idx');
            $table->index(['signal_family', 'state']);
        });

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id', 120)->unique();
            $table->string('supervisor', 80)->nullable();
            $table->string('queue_class', 80)->nullable();
            $table->string('hostname', 120)->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_api_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('operation', 80);
            $table->timestamp('window_started_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('auth_errors')->default(0);
            $table->unsignedInteger('rate_limits')->default(0);
            $table->unsignedInteger('client_errors')->default(0);
            $table->unsignedInteger('server_errors')->default(0);
            $table->unsignedInteger('timeouts')->default(0);
            $table->unsignedInteger('network_errors')->default(0);
            $table->unsignedBigInteger('latency_sum_ms')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'operation', 'window_started_at'], 'provider_api_counters_unique');
            $table->index(['provider', 'window_started_at']);
        });

        Schema::create('ops_dispatcher_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('dispatcher_key', 80)->unique();
            $table->timestamp('last_seen_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_dispatcher_heartbeats');
        Schema::dropIfExists('provider_api_counters');
        Schema::dropIfExists('worker_heartbeats');
        Schema::dropIfExists('operational_alerts');
    }
};
