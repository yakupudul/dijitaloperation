<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator decision (2026-11-25): an account whose digital assets are all marked "Kullanılmıyor" (inactive) is
 * collected once more and then parked until an asset is active again. This marks when that one-time collection
 * was started; null = not yet taken (or the asset became active again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->timestampTz('inactive_collection_at')->nullable()->after('last_collection_success_at');
        });
    }

    public function down(): void
    {
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->dropColumn('inactive_collection_at');
        });
    }
};
