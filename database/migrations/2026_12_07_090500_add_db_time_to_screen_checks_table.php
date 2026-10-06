<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfa taraması: how much of a screen's time went to the database, and its slowest query patterns (with the app code
 * that ran them), so a slow screen's cause is readable from the nightly scan instead of a local probe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('screen_checks', 'db_ms')) {
            Schema::table('screen_checks', function (Blueprint $table): void {
                $table->unsignedInteger('db_ms')->nullable()->after('queries');
                $table->json('slow_queries')->nullable()->after('db_ms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('screen_checks', 'db_ms')) {
            Schema::table('screen_checks', function (Blueprint $table): void {
                $table->dropColumn(['db_ms', 'slow_queries']);
            });
        }
    }
};
