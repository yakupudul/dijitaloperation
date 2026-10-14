<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connector 1.16.0 (Avrupadent 2026-10-14): when the site last asked for its work, what it reported about itself and
     * why MoxDOP last refused an ask, kept in the database (the cache lost "last asked" and the page said "never").
     */
    public function up(): void
    {
        Schema::table('website_connector_delivery', function (Blueprint $table): void {
            $table->timestamp('pull_seen_at')->nullable();
            $table->json('pull_report')->nullable();
            $table->timestamp('pull_refused_at')->nullable();
            $table->string('pull_refusal', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_connector_delivery', function (Blueprint $table): void {
            $table->dropColumn(['pull_seen_at', 'pull_report', 'pull_refused_at', 'pull_refusal']);
        });
    }
};
