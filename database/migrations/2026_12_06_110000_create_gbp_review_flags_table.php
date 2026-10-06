<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yorumlar › "Kaldırılmasını iste": a review the operator reports to Google for removal (the report itself is made in
 * Google's review management tool; Google has no API for it). Kept to follow it until Google removes or keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gbp_review_flags')) {
            return;
        }
        Schema::create('gbp_review_flags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gbp_review_id')->unique();
            $table->unsignedBigInteger('digital_asset_id')->nullable()->index();
            $table->string('reason', 32);
            $table->text('note')->nullable();
            $table->string('status', 16)->default('draft'); // draft | reported | removed | kept
            $table->timestampTz('reported_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_review_flags');
    }
};
