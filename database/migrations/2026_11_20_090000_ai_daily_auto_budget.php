<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily ceiling of the AI work nobody clicked (nightly flow, autopilot, analysts…): it stops until the next day. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_settings', 'ai_daily_auto_budget_usd')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->decimal('ai_daily_auto_budget_usd', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_settings', 'ai_daily_auto_budget_usd')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('ai_daily_auto_budget_usd');
            });
        }
    }
};
