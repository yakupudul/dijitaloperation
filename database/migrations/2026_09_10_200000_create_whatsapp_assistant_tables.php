<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::create('whatsapp_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->string('phone_number_id', 40);
            $table->string('contact_id', 40);
            $table->string('contact_name')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
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
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('message_id', 255);
            $table->string('direction', 12);
            $table->string('message_type', 40);
            $table->longText('body');
            $table->string('reply_to_message_id', 255)->nullable();
            $table->boolean('is_history')->default(false);
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['conversation_id', 'message_id'], 'wa_message_identity');
            $table->index(['conversation_id', 'sent_at', 'id'], 'wa_message_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_webhook_receipts');
    }
};
