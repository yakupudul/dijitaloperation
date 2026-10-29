<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More WhatsApp backups next to the inbox's own (yakup, 2026-10-28): each extra backup is a "line" with its own name and
 * saved key, its chats kept apart from the inbox and exported to Excel on their own. An import without a line is the
 * inbox's own backup, as before, so the backup and key already loaded stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_backup_lines')) {
            Schema::create('whatsapp_backup_lines', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->text('backup_key')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('whatsapp_backup_imports', 'line_id')) {
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->foreignId('line_id')->nullable()->index()->constrained('whatsapp_backup_lines')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_backup_imports', 'line_id')) {
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->dropForeign(['line_id']);
            });
            // SQLite cannot drop a column that still has an index.
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->dropIndex(['line_id']);
            });
            Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
                $table->dropColumn('line_id');
            });
        }
        Schema::dropIfExists('whatsapp_backup_lines');
    }
};
