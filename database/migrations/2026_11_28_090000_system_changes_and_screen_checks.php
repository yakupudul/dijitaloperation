<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geliştirme havuzu (yakup, 2026-10-03):
 *  - system_changes: what Claude (or the operator) proposes to fix or improve in MoxDOP itself (collection errors,
 *    page errors, design). The operator approves; Claude codes it, pushes the branch and writes the deploy commands
 *    here; the operator deploys and marks it; Claude checks the live system and closes it.
 *  - screen_checks: the latest render of each operator screen (status, time, queries, error, text outline), so Claude
 *    finds broken or slow pages without a browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_changes')) {
            Schema::create('system_changes', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 16); // bug | collection | page | design | improvement
                $table->string('title', 200);
                $table->text('detail');
                $table->json('evidence')->nullable();
                $table->string('fingerprint', 64)->nullable()->unique();
                $table->unsignedTinyInteger('priority')->default(2); // 1 acil · 2 normal · 3 düşük
                $table->string('source', 16)->default('claude'); // claude | operator
                $table->string('status', 16)->default('proposed'); // proposed | approved | in_progress | ready | deployed | verified | failed | rejected
                $table->text('operator_note')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->string('branch', 120)->nullable();
                $table->string('commit_sha', 40)->nullable();
                $table->text('deploy_commands')->nullable();
                $table->text('work_note')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('deployed_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->text('verify_note')->nullable();
                $table->timestamps();
                $table->index(['status', 'priority'], 'system_changes_status_idx');
            });
        }
        if (! Schema::hasTable('screen_checks')) {
            Schema::create('screen_checks', function (Blueprint $table): void {
                $table->id();
                $table->string('path', 300)->unique();
                $table->string('label', 200);
                $table->unsignedSmallInteger('status');
                $table->unsignedInteger('duration_ms');
                $table->unsignedInteger('queries');
                $table->text('error')->nullable();
                $table->text('outline')->nullable();
                $table->string('release', 40)->nullable();
                $table->timestamp('checked_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_checks');
        Schema::dropIfExists('system_changes');
    }
};
