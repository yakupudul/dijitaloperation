<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış (yakup 2026-11-04: the template messages were bad): the next message is written by AI from the whole
 * conversation and a scan of the prospect's site. The card keeps the AI's text, its note to the operator, the day to
 * send, and the scan's numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('draft_status', 20)->nullable();
            $table->text('draft_ai')->nullable();
            $table->text('draft_note')->nullable();
            $table->text('draft_summary')->nullable();
            $table->date('draft_send_on')->nullable();
            $table->timestamp('draft_at')->nullable();
            $table->json('site_scan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['draft_status', 'draft_ai', 'draft_note', 'draft_summary', 'draft_send_on', 'draft_at', 'site_scan']);
        });
    }
};
