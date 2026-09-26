<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W5: every paid DataForSEO call adds its cost here, so one account-wide monthly cap covers all features
 * (map grid, reviews, backlinks, area SERP, search demand, prospect audits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataforseo_monthly_spend', function (Blueprint $table): void {
            $table->string('month', 7)->primary();
            $table->decimal('cost_usd', 12, 5)->default(0);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_monthly_spend');
    }
};
