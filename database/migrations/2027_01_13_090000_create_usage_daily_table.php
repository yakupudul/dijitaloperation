<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kök neden planı madde 5 (yakup 2026-11-09): pruning by measured use. One row per screen (route / Livewire component)
 * or scheduled job per Istanbul day: how often it ran, how long, how often it failed. Read through MCP system-health
 * (`usage`); kept 120 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_daily')) {
            return;
        }
        Schema::create('usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 12); // screen | action | job
            $table->string('key', 191);
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->timestamp('last_at')->nullable();
            $table->unique(['kind', 'key', 'day'], 'usage_daily_identity');
            $table->index(['day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily');
    }
};
