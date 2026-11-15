<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Erişim kaydı reads one site's checks by time (and the outage start); the table only had a time index. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uptime_checks') || Schema::hasIndex('uptime_checks', 'uptime_checks_site_time_index')) {
            return;
        }
        Schema::table('uptime_checks', function (Blueprint $table): void {
            $table->index(['digital_asset_id', 'checked_at'], 'uptime_checks_site_time_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('uptime_checks') && Schema::hasIndex('uptime_checks', 'uptime_checks_site_time_index')) {
            Schema::table('uptime_checks', fn (Blueprint $table) => $table->dropIndex('uptime_checks_site_time_index'));
        }
    }
};
