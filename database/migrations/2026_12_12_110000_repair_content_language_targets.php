<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Turkish ideas pointed at /de/ pages (opcklinik.com, 2026-10-12): waiting ideas get a page in their own language once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions') || ! Schema::hasTable('pages')) {
            return;
        }
        try {
            Artisan::call('moxdop:content:fix-languages');
        } catch (Throwable) {
            // A fresh database: no ideas.
        }
    }

    public function down(): void
    {
        // The ideas keep their new pages.
    }
};
