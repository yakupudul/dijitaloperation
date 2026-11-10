<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yapılacaklar + hızlı not (yakup 2026-11-10): a to-do without a date stays on Takvim until it is ticked, a note is a
 * reminder of kind "note" with no date, and both can come from the browser extension's "+" with the page it was
 * written on. Nothing is copied: the reminders table only learns that a date is optional and where an item came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->timestamp('remind_at')->nullable()->change();
            $table->string('url', 1000)->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('capture_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['capture_token']);
            $table->dropColumn('capture_token');
        });
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('url');
        });
    }
};
