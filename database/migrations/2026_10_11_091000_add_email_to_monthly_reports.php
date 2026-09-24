<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When and to whom the monthly report link was emailed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->timestampTz('emailed_at')->nullable();
            $table->text('emailed_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->dropColumn(['emailed_at', 'emailed_to']);
        });
    }
};
