<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink hizmeti, adım 4 (2026-11-06):
 * 1. link_sources.linked_domains: the sites a pool source's page links to (read by the quality gate), so a source that
 *    links to a brand's competitors but not to the brand is offered first (rakip boşluğu, without a link index).
 * 2. link_targets.kind: sayfa (a page picked from Search Console) | miknatis (the brand's link magnet page, kept as a
 *    target as long as it is live).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('link_sources', 'linked_domains')) {
            Schema::table('link_sources', fn (Blueprint $table) => $table->json('linked_domains')->nullable());
        }
        if (! Schema::hasColumn('link_targets', 'kind')) {
            Schema::table('link_targets', fn (Blueprint $table) => $table->string('kind', 12)->default('sayfa'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('link_sources', 'linked_domains')) {
            Schema::table('link_sources', fn (Blueprint $table) => $table->dropColumn('linked_domains'));
        }
        if (Schema::hasColumn('link_targets', 'kind')) {
            Schema::table('link_targets', fn (Blueprint $table) => $table->dropColumn('kind'));
        }
    }
};
