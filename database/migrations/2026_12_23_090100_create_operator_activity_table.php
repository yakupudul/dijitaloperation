<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 4 (2026-10-22): operator time. One row per person, Istanbul day, minute of the day and brand (0 = no brand)
 * with the screens opened and the clicks made in it; a minute with a row is one active minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedSmallInteger('minute');
            $table->unsignedBigInteger('brand_id')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);

            $table->unique(['user_id', 'day', 'minute', 'brand_id'], 'operator_activity_minute_uq');
            $table->index(['day', 'brand_id'], 'operator_activity_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_activity');
    }
};
