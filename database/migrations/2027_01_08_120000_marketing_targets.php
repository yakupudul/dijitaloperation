<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pazarlama hunisi (Moximu büyüme sistemi, Aşama 4): the monthly application target per channel (25 health
 * applications: Meta 12, web 8, Google Ads 3, İşletme Profili 2), editable on the Pazarlama screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_settings', 'marketing_targets')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->json('marketing_targets')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_settings', 'marketing_targets')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('marketing_targets');
            });
        }
    }
};
