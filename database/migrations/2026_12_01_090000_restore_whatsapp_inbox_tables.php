<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp inbox is back (the v2 reset dropped it): the webhook receipts, conversations (customer link, 24-hour
 * window, KVKK opt-out), messages (with the KVKK redaction marker) and Embedded Signup attempts, plus the agency's
 * message retention period. Prospect links are not restored — prospects are gone in v2. Tables that already exist
 * are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_webhook_receipts')) {
            Schema::create('whatsapp_webhook_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
                $table->string('payload_hash', 64);
                $table->longText('payload')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('accepted_count')->default(0);
                $table->unsignedInteger('ignored_count')->default(0);
                $table->string('error_code')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['integration_id', 'payload_hash'], 'wa_receipt_identity');
            });
        }
        if (! Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
                $table->string('phone_number_id', 40);
                $table->string('contact_id', 40);
                $table->string('contact_name')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->string('link_source', 20)->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamp('last_incoming_at')->nullable();
                $table->timestamp('opted_out_at')->nullable();
                $table->unsignedBigInteger('revision')->default(0);
                $table->unsignedBigInteger('suggested_revision')->nullable();
                $table->string('suggestion_status', 20)->default('pending')->index();
                $table->string('suggestion_action', 30)->nullable();
                $table->text('suggestion')->nullable();
                $table->text('rationale')->nullable();
                $table->text('summary')->nullable();
                $table->string('error_code')->nullable();
                $table->unsignedInteger('context_message_count')->default(0);
                $table->boolean('context_truncated')->default(false);
                $table->string('agent_version', 20)->nullable();
                $table->string('route_signature', 1000)->nullable();
                $table->string('settings_fingerprint', 64)->nullable();
                $table->timestamp('suggested_at')->nullable();
                $table->timestamps();
                $table->unique(['integration_id', 'phone_number_id', 'contact_id'], 'wa_conversation_identity');
            });
        }
        if (! Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
                $table->string('message_id', 255);
                $table->string('direction', 12);
                $table->string('message_type', 40);
                $table->longText('body');
                $table->timestampTz('redacted_at')->nullable();
                $table->string('reply_to_message_id', 255)->nullable();
                $table->boolean('is_history')->default(false);
                $table->timestamp('sent_at');
                $table->timestamps();
                $table->unique(['conversation_id', 'message_id'], 'wa_message_identity');
                $table->index(['conversation_id', 'sent_at', 'id'], 'wa_message_order');
            });
        }
        if (! Schema::hasTable('whatsapp_signup_attempts')) {
            Schema::create('whatsapp_signup_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('session_hash', 64);
                $table->string('settings_revision', 64);
                $table->string('mode', 30);
                $table->string('status', 30)->index();
                $table->string('step', 60)->nullable();
                $table->text('payload')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('agency_settings', 'whatsapp_retention_days')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->unsignedSmallInteger('whatsapp_retention_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_webhook_receipts');
        Schema::dropIfExists('whatsapp_signup_attempts');
        if (Schema::hasColumn('agency_settings', 'whatsapp_retention_days')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('whatsapp_retention_days');
            });
        }
    }
};
