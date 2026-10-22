<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit D5 (2026-10-22): the remote backup copy (a WebDAV folder, e.g. a Hetzner Storage Box) is set in the panel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->string('backup_remote_url', 500)->nullable();
            $table->string('backup_remote_username', 190)->nullable();
            $table->text('backup_remote_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['backup_remote_url', 'backup_remote_username', 'backup_remote_password']);
        });
    }
};
