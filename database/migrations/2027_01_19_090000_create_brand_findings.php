<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marka Düzeni 2.1 / 2.2: the brand page's findings store. One row is one thing Claude saw on a brand with its
 * evidence (or looked at and leaves alone, or could not see, or a job only the operator can do); "Çöz" hands it to
 * Claude and the result comes back onto the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('kind', 20)->default('finding');
            $table->string('area', 40);
            $table->string('title', 255);
            $table->string('evidence', 500)->nullable();
            $table->text('saw')->nullable();
            $table->text('plan')->nullable();
            $table->text('needs')->nullable();
            $table->string('approval', 20)->default('none');
            $table->string('status', 20)->default('open');
            $table->text('result')->nullable();
            $table->string('result_url', 500)->nullable();
            $table->text('effect')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['brand_id', 'status']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_findings');
    }
};
