<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal additive Task lifecycle + current Outcome signal columns (ADR-036: no Result table).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tasks', 'completed_by_id')) {
                $table->foreignId('completed_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'completion_note')) {
                $table->text('completion_note')->nullable()->after('completed_by_id');
            }
            if (! Schema::hasColumn('tasks', 'outcome_review_after_at')) {
                $table->timestamp('outcome_review_after_at')->nullable()->after('completion_note');
            }
            if (! Schema::hasColumn('tasks', 'outcome_status')) {
                $table->string('outcome_status')->nullable()->after('outcome_review_after_at')->index();
            }
            if (! Schema::hasColumn('tasks', 'outcome_checked_at')) {
                $table->timestamp('outcome_checked_at')->nullable()->after('outcome_status');
            }
            if (! Schema::hasColumn('tasks', 'outcome_run_id')) {
                $table->foreignId('outcome_run_id')->nullable()->after('outcome_checked_at')->constrained('runs')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'outcome_json')) {
                $table->json('outcome_json')->nullable()->after('outcome_run_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'outcome_run_id')) {
                $table->dropConstrainedForeignId('outcome_run_id');
            }
            if (Schema::hasColumn('tasks', 'completed_by_id')) {
                $table->dropConstrainedForeignId('completed_by_id');
            }

            if (Schema::hasColumn('tasks', 'outcome_status')) {
                $table->dropIndex(['outcome_status']);
            }

            $drop = [];
            foreach ([
                'completed_at',
                'completion_note',
                'outcome_review_after_at',
                'outcome_status',
                'outcome_checked_at',
                'outcome_json',
            ] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
