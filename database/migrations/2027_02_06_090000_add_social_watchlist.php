<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal › Trendler (yakup 2026-11-13: "mevcut keşfet videolarını, trendleri bulabileceğin yöntemler tespit et"): the
 * Instagram accounts whose posts are watched for ones that did far better than that account's usual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->json('social_watchlist')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('social_watchlist');
        });
    }
};
