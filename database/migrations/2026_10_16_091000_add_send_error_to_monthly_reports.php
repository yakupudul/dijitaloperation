<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rapor kuyruğu: why a bulk e-mail did not go out (no address, mail not configured…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->string('send_error', 300)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('monthly_reports')) {
            Schema::table('monthly_reports', function (Blueprint $table): void {
                $table->dropColumn('send_error');
            });
        }
    }
};
