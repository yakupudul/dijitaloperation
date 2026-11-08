<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yeni aday araştırması (yakup 2026-11-08): when an unknown number writes, Claude searches the web from the name and
 * the number (site, Instagram, Google harita kaydı) and the card keeps what it found, its status and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->json('research')->nullable();
            $table->string('research_status', 20)->nullable();
            $table->text('research_note')->nullable();
            $table->timestamp('research_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['research', 'research_status', 'research_note', 'research_at']);
        });
    }
};
