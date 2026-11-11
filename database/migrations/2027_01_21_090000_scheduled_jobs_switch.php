<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bağlantı Düzeni 2.4: the scheduled jobs the operator switched off, by job key (null / empty = every job runs). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'jobs_off')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->json('jobs_off')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'jobs_off')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('jobs_off'));
        }
    }
};
