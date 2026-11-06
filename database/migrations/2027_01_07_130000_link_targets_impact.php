<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink hizmeti, adım 5 (2026-11-06): link_targets.impact keeps the page's numbers 60 and 90 days after its first
 * verified link ({first_link_at, d60: {...}, d90: {...}}), the after side of the comparison with `before`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('link_targets', 'impact')) {
            Schema::table('link_targets', fn (Blueprint $table) => $table->json('impact')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('link_targets', 'impact')) {
            Schema::table('link_targets', fn (Blueprint $table) => $table->dropColumn('impact'));
        }
    }
};
