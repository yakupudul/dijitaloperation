<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KVKK retention: mark a WhatsApp message whose text was redacted, so retention never re-reads an (encrypted) body
 * to decide — it filters on this flag instead. The body column is encrypted; comparing it to a constant in SQL
 * can never match, so a marker column is required for idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->timestampTz('redacted_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });
    }
};
