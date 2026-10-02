<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 — an account's automatic collection can be pinned to an hour of the day (Europe/Istanbul). Null keeps
 * "interval after the last run".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('preferred_hour')->nullable()->after('interval_days');
        });
    }

    public function down(): void
    {
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->dropColumn('preferred_hour');
        });
    }
};
