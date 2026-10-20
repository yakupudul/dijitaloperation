<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel colors chosen in Ayarlar › Genel › Görünüm (yakup 2026-10-20): the accent (main buttons, counters, the
 * highlighted word) and the second color (charts, the dark number panel). Null means the Moximu logo colors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->string('theme_accent', 7)->nullable();
            $table->string('theme_second', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['theme_accent', 'theme_second']);
        });
    }
};
