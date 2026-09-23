<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 1 cleanup — drop empty tables of the removed agency-team workflows
 * (Client Requests, Approvals, QA reviews, Playbooks, Recurring Reviews).
 *
 * A table is dropped only when it exists and has no rows; tables that still hold
 * data are left in place for a later review. Children are listed before parents.
 * The legacy tasks.client_request_id / tasks.recurring_review_run_item_id columns
 * stay; only their foreign keys to a dropped table are removed first.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'recurring_review_run_item_task_links',
        'recurring_review_run_items',
        'recurring_review_runs',
        'recurring_review_check_definitions',
        'recurring_review_schedules',
        'playbook_revision_execution_scopes',
        'playbook_revision_asset_types',
        'playbook_revision_services',
        'playbook_references',
        'playbook_instructions',
        'playbook_revisions',
        'playbooks',
        'approvals',
        'qa_reviews',
        'client_requests',
    ];

    /**
     * Kept tables that may hold a foreign key into one of the dropped tables.
     *
     * @var list<string>
     */
    private const KEPT_REFERENCING_TABLES = ['tasks'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || DB::table($table)->count() !== 0) {
                continue;
            }

            $this->dropInboundForeignKeys($table);

            Schema::disableForeignKeyConstraints();

            try {
                Schema::drop($table);
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    public function down(): void
    {
        // Irreversible cleanup: dropped tables belonged to removed code and are not recreated.
    }

    private function dropInboundForeignKeys(string $droppedTable): void
    {
        foreach (self::KEPT_REFERENCING_TABLES as $keptTable) {
            if (! Schema::hasTable($keptTable)) {
                continue;
            }

            foreach (Schema::getForeignKeys($keptTable) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== $droppedTable) {
                    continue;
                }

                $name = $foreignKey['name'] ?? null;
                $columns = $foreignKey['columns'] ?? [];

                Schema::table($keptTable, function (Blueprint $blueprint) use ($name, $columns): void {
                    $blueprint->dropForeign(is_string($name) && $name !== '' ? $name : $columns);
                });
            }
        }
    }
};
