<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit T2 (2026-10-22, first test run on PostgreSQL): "awaiting_approval" (17 letters) did not fit the 16-letter
 * status column, so a site build in approval mode failed on the live database while SQLite (no length limit) passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('external_write_actions', 'status')) {
            Schema::table('external_write_actions', fn (Blueprint $table) => $table->string('status', 32)->default('queued')->change());
        }
    }

    public function down(): void {}
};
