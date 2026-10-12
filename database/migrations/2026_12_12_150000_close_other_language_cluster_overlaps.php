<?php

use App\Services\Site\ClusterOverlaps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * yakup (2026-10-12, Onarım masası › Küme çakışmaları): overlaps are found in the site's main language only; the open
 * ones found earlier among translations (/it/, /de/ …) are closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions') && Schema::hasTable('pages')) {
            ClusterOverlaps::retireOtherLanguages();
        }
    }

    public function down(): void
    {
        // Closed overlaps stay closed; a sync never reopens them in another language.
    }
};
