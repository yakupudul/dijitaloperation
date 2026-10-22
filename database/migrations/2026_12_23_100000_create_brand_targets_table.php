<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 5 (2026-10-22): each brand's goal is "ayda N talep" (form + call + message, as the brand conversion
 * dictionary counts them), split over the four channels. Claude proposes it from the last three months; yakup approves
 * it with one click. `basis` keeps the months it was read from and this month so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
            $table->unsignedInteger('monthly_leads')->nullable();
            $table->json('split')->nullable();
            $table->unsignedInteger('proposed_leads')->nullable();
            $table->json('proposed_split')->nullable();
            $table->string('status', 16)->default('proposed');
            $table->text('reason')->nullable();
            $table->json('basis')->nullable();
            $table->timestampTz('proposed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_targets');
    }
};
