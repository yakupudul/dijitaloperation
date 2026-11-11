<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Satış Düzeni 4.4: what the weekly look saw on a silent prospect's site last time, to tell an event from a state. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->json('site_watch')->nullable();
            $table->timestamp('site_watched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['site_watch', 'site_watched_at']);
        });
    }
};
