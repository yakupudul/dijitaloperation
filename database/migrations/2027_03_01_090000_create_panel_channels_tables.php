<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli, Sürüm 3 (yakup 2026-11-21 "Sürüm 2 ve 3'ü sırayla yap"): a brand's own message channels. One row
 * per connected WhatsApp number, Instagram account, Facebook page or Meta lead form page; what Meta sends for it is
 * kept as a receipt first and then turned into conversations and leads of that brand only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('connected');
            $table->string('external_id', 64);
            $table->string('account_id', 64)->nullable();
            $table->string('label')->nullable();
            $table->text('token')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['channel', 'external_id']);
            $table->index(['brand_id', 'channel']);
        });

        Schema::create('panel_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('object', 32);
            $table->string('payload_hash', 64)->unique();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('ignored_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_webhook_receipts');
        Schema::dropIfExists('panel_channels');
    }
};
