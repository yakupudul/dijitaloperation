<?php

use App\Services\Site\SiteSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Onayla'ya basılıp siteye hiç gitmeyenler (2026-10-19): rows approved on Öneriler / Rakipler before Onayla kept them
 * open were left out of the Onarım masası and never sent. They go back to open and wait on the desk again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions') || ! Schema::hasTable('external_write_actions')) {
            return;
        }
        SiteSuggestions::returnUnsentApprovals();
        // The overlaps' stored titles and reasons in plain words at once (queued), not at the next night's sync.
        try {
            Artisan::queue('moxdop:clusters:sync-overlaps')->onQueue((string) config('queue.background_queue', 'default'));
        } catch (Throwable) {
            // A fresh database: nothing to rebuild.
        }
    }

    public function down(): void
    {
        // Returned rows stay open on the desk.
    }
};
