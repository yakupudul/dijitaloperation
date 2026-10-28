<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Yedekten Excel al" (yakup, 2026-10-28): a backup can be uploaded only to get its own Excel, without its chats going
 * into the inbox. mode "inbox" is every upload so far (the inbox's own or an extra backup's), "excel" the Excel-only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_backup_imports', 'mode')) {
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->string('mode', 10)->default('inbox');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_backup_imports', 'mode')) {
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->dropColumn('mode');
            });
        }
    }
};
