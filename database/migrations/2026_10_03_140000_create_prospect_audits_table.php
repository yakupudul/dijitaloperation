<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8f: external audit of a prospect — public website checks (free) and optional Google Maps presence for a
 * service keyword (DataForSEO, agency monthly cap). Printable, for the sales conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->string('status', 16)->default('running');
            $table->string('website_url', 500)->nullable();
            $table->string('maps_keyword', 200)->nullable();
            $table->json('website')->nullable();
            $table->json('maps')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_audits');
    }
};
