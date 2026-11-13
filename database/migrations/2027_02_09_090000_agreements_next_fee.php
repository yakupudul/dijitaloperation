<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finans özet (yakup 2026-11-13): a fee that is agreed to rise once something happens ("Google Ads başlayınca 30 bin",
 * "Meta başlarsa 6.500 daha"). The new fee and what it waits on; the running fee stays as it is until it is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->unsignedInteger('next_fee')->nullable();
            $table->string('next_fee_when', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropColumn(['next_fee', 'next_fee_when']);
        });
    }
};
