<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Şef: one weekly plan across the active brands (week start = Monday, Europe/Istanbul). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chief_plans', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start')->unique();
            $table->string('headline', 300)->nullable();
            $table->json('plan')->nullable();
            $table->string('status', 16)->default('ready'); // ready | failed
            $table->string('error', 300)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chief_plans');
    }
};
