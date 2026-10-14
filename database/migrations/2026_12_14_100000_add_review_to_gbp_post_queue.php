<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Günde 1 tık (yakup, 2026-10-14): the AI check of a planned Business Profile post (ok / wrong / unsure, reason, time);
     * only checked posts are approved by the operator's daily click.
     */
    public function up(): void
    {
        Schema::table('gbp_post_queue', function (Blueprint $table): void {
            $table->json('review')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gbp_post_queue', function (Blueprint $table): void {
            $table->dropColumn('review');
        });
    }
};
