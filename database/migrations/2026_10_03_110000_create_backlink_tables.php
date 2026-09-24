<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8c: backlink summaries (brand + approved competitors), the brand's referring domains (new / lost) and
 * link opportunities (competitor intersection, Turkish directories) with an outreach status and a live check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('target', 255);
            $table->boolean('is_competitor')->default(false);
            $table->date('observed_on');
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedBigInteger('backlinks')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedInteger('broken_backlinks')->nullable();
            $table->unsignedSmallInteger('spam_score')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'target', 'observed_on']);
        });

        Schema::create('backlink_referring_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedSmallInteger('spam_score')->nullable();
            $table->boolean('dofollow')->default(true);
            $table->date('first_seen')->nullable();
            $table->date('lost_on')->nullable();
            $table->date('last_seen_on')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'domain']);
        });

        Schema::create('backlink_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('name', 160)->nullable();
            $table->string('source', 16);
            $table->unsignedTinyInteger('competitors_linking')->default(0);
            $table->json('competitor_domains')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedSmallInteger('spam_score')->nullable();
            $table->string('status', 16)->default('new');
            $table->string('link_url', 500)->nullable();
            $table->string('contact', 255)->nullable();
            $table->text('note')->nullable();
            $table->boolean('link_found')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'domain']);
            $table->index(['brand_id', 'status']);
        });

        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->timestampTz('backlinks_refreshed_at')->nullable();
            $table->timestampTz('reviews_refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->dropColumn(['backlinks_refreshed_at', 'reviews_refreshed_at']);
        });
        Schema::dropIfExists('backlink_opportunities');
        Schema::dropIfExists('backlink_referring_domains');
        Schema::dropIfExists('backlink_snapshots');
    }
};
