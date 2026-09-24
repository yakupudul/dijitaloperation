<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8b: service-area coordinates (OpenStreetMap geocoding, public read) for the KML pin file, and the date the
 * owner published the pinned map so grid scans before / after can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_service_areas', function (Blueprint $table): void {
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('geocode_status', 16)->nullable();
            $table->timestampTz('geocoded_at')->nullable();
        });
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('kml_pin_limit')->default(100);
            $table->date('kml_experiment_started_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->dropColumn(['kml_pin_limit', 'kml_experiment_started_on']);
        });
        Schema::table('brand_service_areas', function (Blueprint $table): void {
            $table->dropColumn(['lat', 'lng', 'geocode_status', 'geocoded_at']);
        });
    }
};
