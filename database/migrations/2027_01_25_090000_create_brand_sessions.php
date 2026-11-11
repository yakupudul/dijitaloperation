<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka Düzeni 2.6: a brand's monthly session (one summary, at most 5 jobs, one approval) and its one-page customer report. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_sessions')) {
            return;
        }
        Schema::create('brand_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('month', 7);
            $table->text('summary');
            $table->text('jobs');
            $table->text('client_note')->nullable();
            $table->text('report_lines')->nullable();
            $table->json('numbers')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('token', 48)->unique();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['brand_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_sessions');
    }
};
