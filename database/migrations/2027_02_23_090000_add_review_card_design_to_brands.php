<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google yorum kartı (yakup 2026-11-19): four designs, the business picks one on the link it receives and that one
 * stays for the brand (sakin, antet, renk, cerceve). Empty = not picked yet, the link shows all four.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'review_card_design')) {
            Schema::table('brands', fn (Blueprint $table) => $table->string('review_card_design', 16)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('brands', 'review_card_design')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('review_card_design'));
        }
    }
};
