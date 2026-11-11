<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Günlük Düzen 4.3 + sunucu dışı yedek (yakup 2026-11-11): own calendar entries are written to Google Takvim (the
 * event id is kept on the entry) and the nightly backup is copied into a Google Drive folder (its id is kept here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->string('google_event_id', 190)->nullable();
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->json('google_workspace')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('google_event_id');
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('google_workspace');
        });
    }
};
