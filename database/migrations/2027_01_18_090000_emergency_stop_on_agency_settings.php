<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bağlantı Düzeni 3.3: the emergency stop is one timestamp on the agency settings row (null = writes go as usual). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_settings') || Schema::hasColumn('agency_settings', 'writes_stopped_at')) {
            return;
        }
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->timestamp('writes_stopped_at')->nullable();
            $table->unsignedBigInteger('writes_stopped_by')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'writes_stopped_at')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn(['writes_stopped_at', 'writes_stopped_by']));
        }
    }
};
