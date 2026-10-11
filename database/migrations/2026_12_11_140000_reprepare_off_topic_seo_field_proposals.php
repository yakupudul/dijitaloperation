<?php

use App\Services\Repair\SeoFieldsBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Wrong-topic proposals (yakup, 2026-10-11 — OPC: a dermal-filler Q&A page got a "dental fillings" description): waiting
 * title / description proposals that do not share the page's own subject words lose their prepared value and are
 * prepared again with the new rules; the batch operation adopts its new code prompt (one subject per page, 5 pages of
 * one language and category per call). Approved and applied rows are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions') && Schema::hasTable('pages')) {
            SeoFieldsBatch::clearUngrounded();
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.seo_fields_batch']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        // Cleared proposals are prepared again by the hourly batch; earlier prompt versions stay in the history.
    }
};
