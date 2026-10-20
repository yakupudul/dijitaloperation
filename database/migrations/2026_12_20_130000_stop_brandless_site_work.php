<?php

use App\Services\Site\BrandlessSites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Markasız site (yakup, 2026-10-20): a website without a brand is only built, never serviced. The SEO / content writes,
 * back-dated fills and site-operation AI tasks still waiting for such sites stop once, on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('external_write_actions') || ! Schema::hasTable('ai_tasks') || ! Schema::hasTable('content_site_states')) {
            return;
        }
        BrandlessSites::stopPending();
    }

    public function down(): void
    {
        // Stopped work stays stopped; it starts again on its own once the site is given a brand.
    }
};
