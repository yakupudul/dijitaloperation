<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads budget watch: the latest read-only budget / billing / delivery state of each bound Google Ads or Meta account
 * (account status, spend limit, prepaid balance, today's spend, campaigns out of daily budget, disapproved ads).
 * Checked every two hours; the alert scanner turns it into "budget ran out" alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_budget_status', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->json('data')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_budget_status');
    }
};
