<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tamamlandı" on a WhatsApp conversation: the operator answered it from the phone (MoxDOP never sends), so it leaves
 * the open list until the customer writes again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_conversations', 'done_at')) {
                $table->timestamp('done_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('whatsapp_conversations', 'done_at')) {
                $table->dropColumn('done_at');
            }
        });
    }
};
