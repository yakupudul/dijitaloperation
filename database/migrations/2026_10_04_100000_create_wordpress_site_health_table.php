<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 9c: latest WordPress health per site from the connector v2 (versions, pending updates, Site Health result).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_site_health', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('pending_updates')->default(0);
            $table->unsignedSmallInteger('critical_issues')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_site_health');
    }
};
