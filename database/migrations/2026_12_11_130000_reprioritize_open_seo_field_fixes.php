<?php

use App\Services\Repair\SiteAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Severity fix (yakup, 2026-10-11 — "6686 kritik öneri" on one site): waiting title / description fixes take the new
 * priorities (critical only for a missing / duplicate title on a seen or service / location page; description issues
 * 3–5). Approved and applied rows keep theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions') && Schema::hasTable('pages')) {
            try {
                app(SiteAudit::class)->reprioritizeWaiting();
            } catch (Throwable $exception) {
                report($exception); // a data clean-up never blocks a deploy; the nightly audit applies the same priorities
            }
        }
    }

    public function down(): void
    {
        // The audit re-computes priorities on every run; nothing to undo.
    }
};
