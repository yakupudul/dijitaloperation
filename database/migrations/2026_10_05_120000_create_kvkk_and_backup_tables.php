<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10d: KVKK — data processing agreement per customer and an optional WhatsApp message text retention; system
 * backups — one row per database backup run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->date('kvkk_dpa_signed_on')->nullable();
            $table->string('kvkk_dpa_note', 300)->nullable();
            $table->boolean('kvkk_health_data')->default(false);
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('whatsapp_retention_days')->nullable();
        });
        Schema::create('system_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16);
            $table->string('driver', 16);
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->boolean('remote_copied')->default(false);
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_retention_days');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['kvkk_dpa_signed_on', 'kvkk_dpa_note', 'kvkk_health_data']);
        });
    }
};
