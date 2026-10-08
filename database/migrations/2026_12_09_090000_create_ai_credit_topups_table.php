<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kredi esaslı AI (yakup, 2026-10-08): the API credit the operator loaded per provider (Claude API, OpenAI). Neither
 * provider reports the remaining balance to an API key, so MoxDOP keeps it: loaded credit minus the recorded cost of
 * that provider's calls since the first top-up. A provider with credit stops (falls back) when it reaches zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_topups', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48);
            $table->decimal('amount_usd', 10, 2);
            $table->string('note', 190)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['provider', 'created_at'], 'ai_credit_topups_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_topups');
    }
};
