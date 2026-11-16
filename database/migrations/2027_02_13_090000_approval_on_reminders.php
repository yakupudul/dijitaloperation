<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yapılacaklar'da onay (yakup 2026-11-16: "senin yapacağın ama benim onaylamam gereken bir şey varsa orada benim onayıma
 * sunman yeterli"): a to-do can be a question about Claude's own work. It shows Onayla / Reddet instead of a tick;
 * Onayla hands the job back to Claude as a Moxi işi. Only the answer is stored; done_at is when it was answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            // null = an ordinary item · asked · approved · rejected
            $table->string('approval', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('approval');
        });
    }
};
