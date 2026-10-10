<?php

use App\Services\Ads\AdsCatchUp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kazananlar races per market (city × service) from now on (yakup, 2026-10-10): the daily leaders and ranking are kept
 * per city, so risers / fallers and leadership changes work in every city. Earlier rows (all of Turkey) keep city ''.
 * The per-service rows are rebuilt at once (rules, no AI) so they are written to their cities without waiting a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_winner_snapshots') || Schema::hasColumn('ad_winner_snapshots', 'city')) {
            return;
        }
        $this->addCity();
        if (! app()->runningUnitTests()) {
            try {
                AdsCatchUp::queueServiceStats();
            } catch (Throwable) {
                // The hourly catch-up rebuilds them.
            }
        }
    }

    private function addCity(): void
    {
        Schema::table('ad_winner_snapshots', function (Blueprint $table): void {
            $table->dropUnique('ad_winner_snapshots_unique');
        });
        Schema::table('ad_winner_snapshots', function (Blueprint $table): void {
            $table->string('city', 80)->default('');
            $table->unique(['service_id', 'city', 'snapshot_date'], 'ad_winner_snapshots_market_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_winner_snapshots') || ! Schema::hasColumn('ad_winner_snapshots', 'city')) {
            return;
        }
        Schema::table('ad_winner_snapshots', function (Blueprint $table): void {
            $table->dropUnique('ad_winner_snapshots_market_unique');
        });
        Schema::table('ad_winner_snapshots', function (Blueprint $table): void {
            $table->dropColumn('city');
        });
    }
};
