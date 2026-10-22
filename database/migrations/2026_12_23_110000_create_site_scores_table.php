<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Aşama 7 (2026-10-22): each website's nightly 0-100 score in four layers (teknik, SEO, AEO, kurgu). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_scores')) {
            return;
        }
        Schema::create('site_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->date('day');
            $table->unsignedSmallInteger('score');
            $table->json('layers');
            $table->json('problems')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->timestamps();
            $table->unique(['digital_asset_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_scores');
    }
};
