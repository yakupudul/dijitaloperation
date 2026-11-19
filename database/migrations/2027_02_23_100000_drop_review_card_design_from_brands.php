<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google yorum kartı (yakup 2026-11-19 20:33 "neden seçmek zorunda kalsın"): the business no longer picks a design;
 * every design has its own PDF download, so the brand keeps no choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('brands', 'review_card_design')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('review_card_design'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('brands', 'review_card_design')) {
            Schema::table('brands', fn (Blueprint $table) => $table->string('review_card_design', 16)->nullable());
        }
    }
};
