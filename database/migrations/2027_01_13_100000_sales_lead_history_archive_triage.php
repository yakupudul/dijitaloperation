<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gelen kutusu ve Satış canlı (yakup 2026-11-09): a card built only from imported history (the phone backup, Meta's
 * history sync) does not make debts or steps (history_only); old silent history cards leave the counts into an archive
 * (archived_at, archive_reason; a live message or the operator brings them back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_leads', 'history_only')) {
                $table->boolean('history_only')->default(false);
            }
            if (! Schema::hasColumn('sales_leads', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('sales_leads', 'archive_reason')) {
                $table->string('archive_reason', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_leads', 'archived_at')) {
            Schema::table('sales_leads', function (Blueprint $table): void {
                $table->dropIndex(['archived_at']);
            });
        }
        Schema::table('sales_leads', function (Blueprint $table): void {
            foreach (['history_only', 'archived_at', 'archive_reason'] as $column) {
                if (Schema::hasColumn('sales_leads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
