<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('agency_name')->default('MoxDOP');
            $table->string('portal_name')->default('MoxDOP');
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('Europe/Istanbul');
            $table->string('display_currency', 8)->default('TRY');
            $table->string('week_starts_on', 16)->default('monday');
            $table->string('analytical_date_range', 32)->default('last_28');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_settings');
    }
};
