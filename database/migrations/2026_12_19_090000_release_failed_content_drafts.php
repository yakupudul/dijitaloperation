<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Gönderilemeyen taslaklar (2026-10-19): the articles whose WordPress draft write failed (Avrupadent, 14 Oct: the site
 * was not fetching its work) stood as "Taslak gönderildi" while WordPress had none of them. They go back to "send" with
 * the reason; sending stays the operator's click.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions') || ! Schema::hasTable('external_write_actions')) {
            return;
        }
        try {
            Artisan::call('moxdop:content:release-failed-drafts');
        } catch (Throwable) {
            // A fresh database: nothing to release.
        }
    }

    public function down(): void
    {
        // Released ideas stay ready to send.
    }
};
