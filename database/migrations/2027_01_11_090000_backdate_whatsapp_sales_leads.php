<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cards opened from WhatsApp chats count from the chat's first message (yakup 2026-11-08: the number connected that
 * morning brought old chats, and Pazarlama showed 553 of them as November applications). Only cards whose chat began
 * more than a day before the card; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_leads') || ! Schema::hasTable('whatsapp_messages')) {
            return;
        }
        $rows = DB::table('sales_leads')->join('whatsapp_messages', 'whatsapp_messages.conversation_id', '=', 'sales_leads.conversation_id')
            ->where('sales_leads.source', 'whatsapp')->whereNotNull('sales_leads.conversation_id')
            ->groupBy('sales_leads.id', 'sales_leads.created_at')
            ->selectRaw('sales_leads.id as id, sales_leads.created_at as created_at, min(whatsapp_messages.sent_at) as first_at')
            ->get();
        foreach ($rows as $row) {
            if ($row->first_at === null || $row->created_at === null) {
                continue;
            }
            $first = CarbonImmutable::parse($row->first_at);
            if ($first->lt(CarbonImmutable::parse($row->created_at)->subDay())) {
                DB::table('sales_leads')->where('id', $row->id)->update(['created_at' => $first]);
            }
        }
    }

    public function down(): void {}
};
