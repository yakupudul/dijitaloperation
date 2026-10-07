<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka tamamlama (yakup, 2026-10-07 "Hemen kullan"): a proposal Claude started itself is applied as soon as it is ready. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_setup_proposals', function (Blueprint $table): void {
            $table->boolean('auto_apply')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('brand_setup_proposals', function (Blueprint $table): void {
            $table->dropColumn('auto_apply');
        });
    }
};
