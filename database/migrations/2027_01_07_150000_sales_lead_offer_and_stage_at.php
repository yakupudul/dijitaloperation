<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adaylar tablosu (yakup 2026-11-07): what was offered (monthly ₺ and which service; a price seen in a sent message is
 * filled in as a suggestion until the operator confirms it) and since when the card is in its stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->unsignedInteger('offer_amount')->nullable();
            $table->string('offer_service', 30)->nullable();
            $table->boolean('offer_confirmed')->default(false);
            $table->timestamp('stage_at')->nullable();
        });

        // Since when: the last stage change written on the card, else when the card was opened.
        DB::table('sales_leads')->orderBy('id')->select(['id', 'created_at'])->chunk(200, function ($leads): void {
            foreach ($leads as $lead) {
                $at = DB::table('sales_lead_events')->where('lead_id', $lead->id)->where('kind', 'stage')->max('happened_at');
                DB::table('sales_leads')->where('id', $lead->id)->update(['stage_at' => $at ?? $lead->created_at]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['offer_amount', 'offer_service', 'offer_confirmed', 'stage_at']);
        });
    }
};
