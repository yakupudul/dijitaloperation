<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admin denetimi 2026-11-07: every screen's header asks for the last finished top-level AI work
 * (AiLiveOperations::finishedRecently, ~60 ms on each page and on each indicator refresh) and Postgres sorted the whole
 * table for it. A partial index on the top-level rows in the same order answers it from the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists ai_live_top_finished_idx on ai_live_operations (finished_at desc, id desc) where parent_id is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists ai_live_top_finished_idx');
    }
};
