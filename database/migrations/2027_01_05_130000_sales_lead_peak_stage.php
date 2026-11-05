<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satış 4. hafta: how far a card got before it was won or lost (the month report's funnel and the loss observations).
 */
return new class extends Migration
{
    private const array FLOW = ['new', 'contacted', 'talking', 'meeting', 'priced', 'thinking'];

    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('peak_stage', 20)->nullable();
        });

        // Backfill from what is known: the current stage, the stage changes written on the card, and the messages.
        DB::table('sales_leads')->orderBy('id')->select(['id', 'stage'])->chunk(200, function ($leads): void {
            foreach ($leads as $lead) {
                $peak = array_search($lead->stage, self::FLOW, true);
                $peak = $peak === false ? -1 : $peak;
                $events = DB::table('sales_lead_events')->where('lead_id', $lead->id)->whereIn('kind', ['stage', 'message_out', 'message_in'])->get(['kind', 'meta']);
                foreach ($events as $event) {
                    $candidates = match ($event->kind) {
                        'message_out' => ['contacted'],
                        'message_in' => ['talking'],
                        default => array_values(array_filter((array) (json_decode((string) $event->meta, true) ?: []), 'is_string')),
                    };
                    foreach ($candidates as $stage) {
                        $at = array_search($stage, self::FLOW, true);
                        $peak = $at !== false && $at > $peak ? $at : $peak;
                    }
                }
                if ($peak >= 0) {
                    DB::table('sales_leads')->where('id', $lead->id)->update(['peak_stage' => self::FLOW[$peak]]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn('peak_stage');
        });
    }
};
