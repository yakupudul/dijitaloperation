<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI iş kuyruğu (Claude MCP): an operation delegated to Claude does not call a provider; its instructions, input and
 * output schema wait here until Claude (MCP server) submits a result, then the job that asked is dispatched again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_tasks')) {
            return;
        }
        Schema::create('ai_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 80)->index();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 200)->nullable();
            // One run of the asking job: its calls are numbered in order (sequence) so a re-run gets each answer back.
            $table->string('resume_key', 64);
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('prompt_version_id')->nullable()->constrained('prompt_versions')->nullOnDelete();
            $table->longText('instructions');
            $table->longText('input');
            $table->json('output_schema');
            $table->json('output')->nullable();
            $table->longText('resume');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['resume_key', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
