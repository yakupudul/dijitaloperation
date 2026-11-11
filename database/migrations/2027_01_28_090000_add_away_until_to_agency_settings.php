<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Günlük Düzen 3.5 (izin modu): the day up to which the operator is away. While it holds, only money alarms reach the
 * phone; everything else waits in the morning summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_settings', 'away_until')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->date('away_until')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_settings', 'away_until')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('away_until'));
        }
    }
};
