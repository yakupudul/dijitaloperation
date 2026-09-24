<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp inbox: track the last INBOUND message time (for the 24-hour customer-service reply window indicator) and
 * a KVKK opt-out time set when a contact sends a STOP/DUR-type message. Both are informational; MoxDOP never sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->timestamp('last_incoming_at')->nullable()->after('last_message_at');
            $table->timestamp('opted_out_at')->nullable()->after('last_incoming_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->dropColumn(['last_incoming_at', 'opted_out_at']);
        });
    }
};
