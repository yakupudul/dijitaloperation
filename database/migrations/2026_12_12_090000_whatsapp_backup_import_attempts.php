<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp backup extraction can be cut off (worker killed, server restarted) without the job's failure hook running,
 * which left the import "queued" / "running" for good. Each queueing now counts an attempt and records when it was
 * queued, so a stalled extraction is noticed and started again, and a late job of an earlier attempt does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_backup_imports', 'attempt')) {
                $table->unsignedSmallInteger('attempt')->default(0);
            }
            if (! Schema::hasColumn('whatsapp_backup_imports', 'queued_at')) {
                $table->timestamp('queued_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_backup_imports', function (Blueprint $table): void {
            $table->dropColumn(['attempt', 'queued_at']);
        });
    }
};
