<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finans (yakup 2026-11-13: "fatura istemiyor"): a job whose payer takes no invoice. Its fee is the money that comes
 * in as it is: no KDV is added in reminders or when money is placed, and it is never listed as "para var, fatura yok".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->boolean('no_invoice')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropColumn('no_invoice');
        });
    }
};
