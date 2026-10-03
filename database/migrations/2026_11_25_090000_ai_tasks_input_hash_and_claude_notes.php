<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP Faz 2 hazırlığı:
 *  - ai_tasks.input_hash: a re-run finds the answer of a call by operation + input (not by position), so a run that
 *    skips steps it already stored (Eşleştir in rounds) still gets the right answer.
 *  - claude_notes: Claude's own working notes over MCP (observation, hypothesis, proposal, follow-up), append-only and
 *    kept apart from the brand file and the operator's notes; never read by another AI operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_tasks') && ! Schema::hasColumn('ai_tasks', 'input_hash')) {
            Schema::table('ai_tasks', function (Blueprint $table): void {
                $table->char('input_hash', 64)->nullable()->after('sequence');
                $table->index(['resume_key', 'operation', 'input_hash'], 'ai_tasks_resume_input_idx');
            });
        }
        if (! Schema::hasTable('claude_notes')) {
            Schema::create('claude_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 16); // observation | hypothesis | proposal | followup
                $table->text('text');
                $table->json('refs')->nullable();
                $table->string('status', 16)->default('open'); // open | done | superseded
                $table->foreignId('supersedes_id')->nullable()->constrained('claude_notes')->nullOnDelete();
                $table->timestamps();
                $table->index(['brand_id', 'status'], 'claude_notes_brand_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_notes');
        if (Schema::hasColumn('ai_tasks', 'input_hash')) {
            Schema::table('ai_tasks', function (Blueprint $table): void {
                $table->dropIndex('ai_tasks_resume_input_idx');
                $table->dropColumn('input_hash');
            });
        }
    }
};
