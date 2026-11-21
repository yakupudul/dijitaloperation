<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli, Sürüm 2 (yakup 2026-11-21 "Sürüm 2 ve 3'ü sırayla yap"): the inbox's conversations and messages
 * (first filled from a brand's own WhatsApp backup), and the imports a brand runs itself (backup, Excel) with what
 * each one added, so one click takes it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16)->default('uploading');
            $table->string('file_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('path')->nullable();
            $table->text('secret')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'kind', 'status']);
        });

        Schema::create('panel_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('contact_key', 64);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->foreignId('person_id')->nullable()->constrained('panel_people')->nullOnDelete();
            $table->string('origin', 16)->default('backup');
            $table->text('last_preview')->nullable();
            $table->boolean('last_outgoing')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_incoming_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'channel', 'contact_key']);
            $table->index(['brand_id', 'channel', 'last_message_at']);
        });

        Schema::create('panel_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('panel_conversations')->cascadeOnDelete();
            $table->string('external_id');
            $table->boolean('outgoing')->default(false);
            $table->string('type', 24)->default('text');
            $table->text('body')->nullable();
            $table->timestamp('sent_at');
            $table->foreignId('import_id')->nullable()->constrained('panel_imports')->nullOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'external_id']);
            $table->index(['conversation_id', 'sent_at']);
        });

        Schema::table('panel_people', function (Blueprint $table): void {
            $table->foreignId('import_id')->nullable()->constrained('panel_imports')->nullOnDelete();
        });
        Schema::table('panel_requests', function (Blueprint $table): void {
            $table->foreignId('import_id')->nullable()->constrained('panel_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('panel_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_id');
        });
        Schema::table('panel_people', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_id');
        });
        Schema::dropIfExists('panel_messages');
        Schema::dropIfExists('panel_conversations');
        Schema::dropIfExists('panel_imports');
    }
};
