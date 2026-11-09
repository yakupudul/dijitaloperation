<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moxi (panel asistanı, yakup 2026-11-09): every line of the corner chat. role: user (what the operator wrote), moxi
 * (the answer), card (an action waiting for the operator's Yap), request (a job handed to Claude through MCP), audit (an
 * improvement Moxi noticed, linked to the Geliştirme havuzu). status: pending → done / failed / cancelled. cost_usd is
 * the Claude API cost of the turn and counts toward Ayarlar › AI's TL limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moxi_messages')) {
            return;
        }
        Schema::create('moxi_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 12);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 12)->nullable();
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->string('page', 255)->nullable();
            $table->boolean('hidden')->default(false); // "Temizle": off the chat view, still Moxi's memory
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moxi_messages');
    }
};
