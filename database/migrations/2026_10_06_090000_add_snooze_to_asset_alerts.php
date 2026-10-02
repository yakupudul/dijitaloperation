<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 11a — Uyarılar: an operator can snooze an open alert until a date; a re-opened alert starts unsnoozed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_alerts', function (Blueprint $table): void {
            $table->timestamp('snoozed_until')->nullable()->after('resolved_at');
            $table->foreignId('snoozed_by')->nullable()->after('snoozed_until')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('snoozed_by');
            $table->dropColumn('snoozed_until');
        });
    }
};
