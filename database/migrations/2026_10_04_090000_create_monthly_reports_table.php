<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 9a: monthly client report v2 — a frozen payload per brand and month (channel KPIs vs last month and last
 * year, daily series, done work and its measured effect), an optional AI commentary written on click, the
 * operator's own note, and a public signed link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('month', 7);
            $table->json('payload');
            $table->text('operator_note')->nullable();
            $table->json('commentary')->nullable();
            $table->string('commentary_status', 16)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
