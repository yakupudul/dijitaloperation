<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli › Pazarlama (2026-11-28): a month's report reaches the panel only when the operator publishes it;
 * he may leave single work lines out, and sees when the brand first opened it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_sessions', function (Blueprint $table): void {
            $table->timestamp('panel_published_at')->nullable();
            $table->json('panel_hidden_lines')->nullable();
            $table->timestamp('panel_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_sessions', function (Blueprint $table): void {
            $table->dropColumn(['panel_published_at', 'panel_hidden_lines', 'panel_read_at']);
        });
    }
};
