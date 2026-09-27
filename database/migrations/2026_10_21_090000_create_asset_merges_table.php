<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopya web sitesi birleştirme kaydı: an Admin merged a duplicate website asset (same host) into a keeper. Append-only;
 * the asset ids are plain columns so the record survives later deletes. `moved` / `dropped` hold row counts per table.
 * The archived duplicate points at its keeper (digital_assets.merged_into_asset_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_merges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keeper_id');
            $table->unsignedBigInteger('duplicate_id');
            $table->string('host')->nullable();
            $table->boolean('cross_customer')->default(false);
            $table->unsignedBigInteger('ownership_transfer_id')->nullable();
            $table->json('moved')->nullable();
            $table->json('dropped')->nullable();
            $table->json('snapshot')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('keeper_id', 'asset_merges_keeper_idx');
            $table->index('duplicate_id', 'asset_merges_duplicate_idx');
        });

        if (! Schema::hasColumn('digital_assets', 'merged_into_asset_id')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                $table->unsignedBigInteger('merged_into_asset_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('digital_assets', 'merged_into_asset_id')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                $table->dropColumn('merged_into_asset_id');
            });
        }
        Schema::dropIfExists('asset_merges');
    }
};
