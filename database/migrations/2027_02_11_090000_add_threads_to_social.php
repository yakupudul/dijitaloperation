<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads (yakup 2026-11-13: "Blog paylaşımları Threads'e gitse?"): the Threads app's ID / secret (Meta app › Threads
 * use case) and, on a post, the short text Threads gets. Threads only gets posts that carry this text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('agency_settings', 'social_threads_app_id')) {
                $table->string('social_threads_app_id', 32)->nullable();
                $table->text('social_threads_app_secret')->nullable();
            }
        });
        Schema::table('social_posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('social_posts', 'threads_text')) {
                $table->text('threads_text')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['social_threads_app_id', 'social_threads_app_secret']);
        });
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('threads_text');
        });
    }
};
