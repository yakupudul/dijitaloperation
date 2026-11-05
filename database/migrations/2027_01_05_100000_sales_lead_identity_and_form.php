<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış (yakup 2026-11-05): a prospect is one person by phone or e-mail; a shared site alone only asks (two doctors of one
 * clinic are two cards). "Bizi nereden duydu" is kept apart from the channel they wrote on. moximu.com's forms post to
 * MoxDOP with a token made in the panel; where the form came from (page, utm, gclid) stays on the card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('email')->nullable()->index();
            $table->string('heard_from', 30)->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->json('origin')->nullable();
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->text('lead_form_token')->nullable();
            $table->unsignedBigInteger('lead_form_site_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->dropColumn(['email', 'heard_from', 'duplicate_of_id', 'origin']);
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['lead_form_token', 'lead_form_site_id']);
        });
    }
};
