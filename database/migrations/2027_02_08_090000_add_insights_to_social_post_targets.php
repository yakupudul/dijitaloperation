<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram ölçümü (büyüme yol haritası, yakup 2026-11-13): each published Instagram post keeps its reach, saves, sends,
 * likes, comments and views, read from the Graph API, so the autopilot can judge posts by what Instagram rewards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table): void {
            $table->json('insights')->nullable();
            $table->timestampTz('insights_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table): void {
            $table->dropColumn(['insights', 'insights_at']);
        });
    }
};
