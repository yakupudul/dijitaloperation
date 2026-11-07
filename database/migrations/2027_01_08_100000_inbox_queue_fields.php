<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gelen kutusu (Moximu büyüme sistemi, Aşama 2): a chat put off with "Sonra" leaves the queue until that day, and a
 * WhatsApp conversation that started from a Meta ad keeps the ad's referral so the record knows where it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_conversations', 'snoozed_until')) {
                $table->timestamp('snoozed_until')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_conversations', 'referral')) {
                $table->json('referral')->nullable();
            }
        });
        Schema::table('social_threads', function (Blueprint $table): void {
            if (! Schema::hasColumn('social_threads', 'snoozed_until')) {
                $table->timestamp('snoozed_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            foreach (['snoozed_until', 'referral'] as $column) {
                if (Schema::hasColumn('whatsapp_conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('social_threads', function (Blueprint $table): void {
            if (Schema::hasColumn('social_threads', 'snoozed_until')) {
                $table->dropColumn('snoozed_until');
            }
        });
    }
};
