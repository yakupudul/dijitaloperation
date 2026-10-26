<?php

use App\Services\Queries\QueryAutopilot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgu triage v4 (yakup, 2026-10-26: "haftalardır sistemde atanmamış ya da silinmemiş bu sorgular"): v3 let the AI
 * leave a query undecided ("none"), which marked it seen and kept it unassigned for good (~36k). Every query now gets a
 * service or a filter term (and is deleted); a service the sector lacks is created in the catalog ("ilgili hizmet yok ise
 * oluşturulacak"). The queries left undecided are asked once more with the new prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queries')) {
            return;
        }
        QueryAutopilot::inServicedSectors(DB::table('queries')->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)
            ->whereNotNull('sector_id')->whereNotNull('ai_checked_at'))->update(['ai_checked_at' => null]);
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['queries.triage']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        // The queries are asked again; nothing to undo.
    }
};
