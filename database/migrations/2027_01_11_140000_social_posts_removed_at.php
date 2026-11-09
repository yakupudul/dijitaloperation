<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal medya › Instagram görünümü (yakup 2026-11-08): a published post taken off the grid. Facebook posts are deleted
 * on Facebook too; Instagram's API cannot delete a post, so it is deleted in the app and only leaves our grid here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->timestampTz('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
        });
    }
};
