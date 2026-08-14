<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 42 — canonical Client Request persistence + Request→Task bridge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_requests')) {
            Schema::create('client_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('brand_id')->constrained()->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('service_definition_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_contact_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 32);
                $table->string('channel', 32)->nullable();
                $table->string('priority', 32)->nullable();
                $table->string('effort', 64)->nullable();
                $table->string('due_label', 64)->nullable();
                $table->date('due_date')->nullable();

                // Intake scope snapshot (historical). Never overwritten by later Service Scope changes.
                $table->string('intake_scope_state', 64)->nullable();
                $table->json('intake_scope_snapshot')->nullable();
                $table->timestamp('intake_scope_assessed_at')->nullable();

                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index(['digital_asset_id']);
                $table->index(['owner_user_id']);
                $table->index(['service_definition_id']);
                $table->index(['created_at']);
                $table->index(['priority']);
            });
        }

        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'client_request_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('client_request_id')
                    ->nullable()
                    ->after('recommendation_id')
                    ->constrained('client_requests')
                    ->nullOnDelete();
                $table->string('client_request_task_idempotency_key')->nullable()->unique();
                $table->index('client_request_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'client_request_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_request_id');
                $table->dropUnique(['client_request_task_idempotency_key']);
                $table->dropColumn('client_request_task_idempotency_key');
            });
        }

        Schema::dropIfExists('client_requests');
    }
};
