<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal paylaşım düzeni (yakup 2026-11-12): the Admin picks the weekdays and the hour; approved posts line up and go
 * out one per chosen day in the order he set. A post with a fixed date (`pinned`) keeps its own time and its day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->boolean('pinned')->default(false);
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->json('social_schedule')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('pinned');
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('social_schedule');
        });
    }
};
