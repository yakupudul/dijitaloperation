<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorular ve yorumlar (yakup, 2026-10-27): the brand switch "Sitede yorum ve soru göster", the questions of every
 * assigned page of a site (asked on the site or written from the page's search demand) with the answer the operator
 * approves, and "Sitede gizle" on a Google review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'showcase_enabled')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->boolean('showcase_enabled')->default(false);
            });
        }
        if (! Schema::hasColumn('gbp_reviews', 'site_hidden')) {
            Schema::table('gbp_reviews', function (Blueprint $table): void {
                $table->boolean('site_hidden')->default(false);
            });
        }
        if (! Schema::hasTable('site_questions')) {
            Schema::create('site_questions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('brand_id')->index();
                $table->unsignedBigInteger('website_asset_id');
                // The page on the site: "post:12" or "term:4" (the plugin's assignment).
                $table->string('target', 64);
                $table->string('target_title')->nullable();
                $table->text('target_url')->nullable();
                // site = a visitor asked it on the site; data = written from the page's search demand.
                $table->string('source', 16);
                // The question post on the site (only for site questions; the visitor's name and contact stay there).
                $table->unsignedBigInteger('local_id')->nullable();
                $table->text('question');
                $table->text('answer')->nullable();
                $table->text('link_url')->nullable();
                $table->string('link_title')->nullable();
                // new → ready (on the desk) → published; private / spam / hidden never show.
                $table->string('status', 16)->default('new');
                $table->unsignedBigInteger('suggestion_id')->nullable()->index();
                $table->unsignedInteger('position')->default(0);
                $table->text('error')->nullable();
                $table->timestampTz('asked_at')->nullable();
                $table->timestampTz('published_at')->nullable();
                $table->timestampsTz();
                $table->index(['website_asset_id', 'target', 'status']);
                $table->unique(['website_asset_id', 'local_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_questions');
        Schema::table('gbp_reviews', function (Blueprint $table): void {
            $table->dropColumn('site_hidden');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('showcase_enabled');
        });
    }
};
