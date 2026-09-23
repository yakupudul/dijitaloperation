<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 1 cleanup: drops the tables of the removed "agency brain" stack (Intelligence Evaluation,
 * Sector Learning, Brand Experiences, Business Outcomes + outcome recheck). A table is dropped only
 * when it exists and is empty; tables that still hold rows are left for manual review.
 */
return new class extends Migration
{
    /**
     * Children before parents.
     *
     * @var list<string>
     */
    private array $tables = [
        'intelligence_evaluation_judge_results',
        'intelligence_evaluation_assertion_results',
        'intelligence_evaluation_human_reviews',
        'intelligence_evaluation_baselines',
        'intelligence_evaluation_case_runs',
        'intelligence_evaluation_runs',
        'sector_learning_lineage_entries',
        'sector_learning_revisions',
        'sector_learning_artifacts',
        'brand_experience_evidence_links',
        'brand_experience_offerings',
        'brand_experience_goals',
        'brand_experience_revisions',
        'brand_experiences',
        'business_outcome_recheck_runs',
        'business_outcome_recheck_schedule_recipients',
        'business_outcome_recheck_schedules',
        'business_outcome_observation_revisions',
        'business_outcome_observations',
        'business_outcome_import_batches',
        'business_outcome_definitions',
    ];

    public function up(): void
    {
        $droppable = array_values(array_filter(
            $this->tables,
            fn (string $table): bool => Schema::hasTable($table) && DB::table($table)->count() === 0,
        ));

        // PostgreSQL refuses to drop a table another table still references (sector_learning_artifacts ↔
        // sector_learning_revisions reference each other), even with constraints deferred. CASCADE removes
        // only those foreign-key constraints, never rows of other tables.
        if (DB::getDriverName() === 'pgsql') {
            foreach ($droppable as $table) {
                DB::statement('DROP TABLE IF EXISTS '.DB::getQueryGrammar()->wrapTable($table).' CASCADE');
            }

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($droppable as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the removed feature code no longer exists to use these tables.
    }
};
