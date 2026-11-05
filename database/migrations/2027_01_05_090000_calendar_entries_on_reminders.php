<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Takvim (yakup 2026-11-05): the operator's own tasks and meetings. They live in the reminders table (a reminder is
 * already a dated task with a phone push), so nothing is migrated or copied: a meeting is a reminder with an end time,
 * a place or link and, once it is over, the note written about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->string('kind', 12)->default('task');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->unsignedBigInteger('sales_lead_id')->nullable()->index();
            $table->string('place', 500)->nullable();
            $table->text('outcome')->nullable();
            $table->timestamp('followup_notified_at')->nullable();
            $table->index(['user_id', 'remind_at'], 'reminders_user_day_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex('reminders_user_day_idx');
            $table->dropIndex(['sales_lead_id']);
            $table->dropColumn(['kind', 'ends_at', 'all_day', 'sales_lead_id', 'place', 'outcome', 'followup_notified_at']);
        });
    }
};
