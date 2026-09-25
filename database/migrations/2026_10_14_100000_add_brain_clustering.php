<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 2: the existing per-service query clusters become page-sized topic clusters (one cluster =
 * one page) with a page type and a search intent; cluster → URL targets remember who set them; and keyword
 * cannibalization (two own URLs splitting one cluster) is recorded per website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_query_clusters', function (Blueprint $t): void {
            $t->string('page_type', 16)->nullable();
            $t->string('intent', 24)->nullable();
            $t->text('head_query')->nullable();
            $t->string('source', 16)->default('manual');
            $t->json('signals')->nullable();
        });
        Schema::table('library_cluster_targets', function (Blueprint $t): void {
            $t->string('source', 16)->default('operator');
            $t->decimal('score', 5, 4)->nullable();
        });
        Schema::create('brain_cannibalizations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->foreignId('brand_id')->nullable()->index();
            $t->foreignId('service_id')->nullable()->index();
            $t->foreignId('cluster_id')->nullable()->index();
            $t->text('subject');
            $t->json('pages');
            $t->unsignedBigInteger('impressions')->default(0);
            $t->decimal('second_share', 5, 4);
            $t->string('status', 16)->default('open');
            $t->timestamp('detected_at');
            $t->timestamps();
            $t->index(['digital_asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_cannibalizations');
        Schema::table('library_cluster_targets', function (Blueprint $t): void {
            $t->dropColumn(['source', 'score']);
        });
        Schema::table('library_query_clusters', function (Blueprint $t): void {
            $t->dropColumn(['page_type', 'intent', 'head_query', 'source', 'signals']);
        });
    }
};
