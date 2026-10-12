<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Google Ads strategy can carry a structured campaign plan (campaigns, ad groups, keywords, negatives, ads) beside
 * its text, so the operator downloads a Google Ads Editor import file once he approves the strategy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            if (! Schema::hasColumn('strategies', 'plan')) {
                $table->json('plan')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
