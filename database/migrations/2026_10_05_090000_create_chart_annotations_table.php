<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10a: chart notes — Google algorithm updates and regulation changes (all brands) and brand events
 * (campaign, site change, other). Shown on report charts so a rise or drop can be read in context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('kind', 16);
            $table->string('title', 200);
            $table->text('note')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'starts_on']);
            $table->index(['kind', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_annotations');
    }
};
