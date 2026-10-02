<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-070: proposed website fixes (SEO title / description, alt text, schema, redirect, noindex, canonical, internal
 * link, content update, new page). A row is found by rules, filled by AI or the operator, and applied to WordPress
 * only through an Admin-approved external_write_actions row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_fix_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('type', 24);
            $table->unsignedTinyInteger('phase')->default(1);
            $table->string('object_id', 32)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('label', 255);
            $table->text('reason')->nullable();
            $table->json('current')->nullable();
            $table->json('proposed')->nullable();
            $table->string('proposed_by', 16)->nullable(); // ai | operator
            $table->string('status', 16)->default('open'); // open | queued | applied | failed | undone | dismissed
            $table->foreignId('write_action_id')->nullable()->constrained('external_write_actions')->nullOnDelete();
            $table->string('change_id', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->char('item_key', 64);
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'item_key'], 'site_fix_items_asset_key_uq');
            $table->index(['digital_asset_id', 'status', 'phase'], 'site_fix_items_asset_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_fix_items');
    }
};
