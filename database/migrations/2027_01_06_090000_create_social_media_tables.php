<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal medya yönetimi (ADR-083): Moximu's own Instagram, Facebook and LinkedIn accounts, the planned posts (one post,
 * several channels) and one row per channel that is published after the Admin's approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_accounts')) {
            Schema::create('social_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('platform', 16); // instagram | facebook | linkedin
                $table->string('external_id', 64);
                $table->string('name')->nullable();
                $table->string('username')->nullable();
                $table->text('access_token')->nullable(); // encrypted page token (Instagram uses its Page's token)
                $table->string('page_id', 64)->nullable(); // Instagram: the linked Facebook Page
                $table->string('picture_url', 1000)->nullable();
                $table->boolean('enabled')->default(true);
                $table->json('meta')->nullable();
                $table->timestampTz('connected_at')->nullable();
                $table->timestampsTz();
                $table->unique(['platform', 'external_id']);
            });
        }

        if (! Schema::hasTable('social_posts')) {
            Schema::create('social_posts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('kind', 16); // image | carousel | reels | story
                $table->string('pillar', 24)->nullable(); // rehber | vaka | gundem | anlatim | guven
                $table->string('title', 200)->nullable(); // internal label
                $table->text('caption')->nullable(); // Instagram + Facebook
                $table->text('linkedin_text')->nullable();
                $table->json('media'); // [{path, type: image|video, width?, height?}]
                $table->string('source_url', 1000)->nullable();
                $table->timestampTz('publish_at');
                $table->string('status', 16)->default('draft'); // draft | approved | published | partial | failed | skipped | expired
                $table->string('created_by', 16)->default('operator'); // operator | claude
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('approved_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestampsTz();
                $table->index(['status', 'publish_at'], 'social_posts_status_time_idx');
            });
        }

        if (! Schema::hasTable('social_post_targets')) {
            Schema::create('social_post_targets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
                $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->string('status', 16)->default('pending'); // pending | processing | published | failed | skipped | manual | done
                $table->string('container_id', 64)->nullable(); // Instagram media container while it processes
                $table->string('external_id', 64)->nullable();
                $table->string('permalink', 1000)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->string('error', 500)->nullable();
                $table->timestampTz('published_at')->nullable();
                $table->timestampsTz();
                $table->unique(['social_post_id', 'social_account_id']);
                $table->index(['status'], 'social_post_targets_status_idx');
            });
        }

        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'social_meta_config_id')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->string('social_meta_config_id', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'social_meta_config_id')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('social_meta_config_id');
            });
        }
    }
};
