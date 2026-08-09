<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-agnostic Evidence freshness fields for paid-request cost guards.
 * Existing rows remain valid with null fingerprint / fresh_until.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evidence')) {
            return;
        }

        Schema::table('evidence', function (Blueprint $table): void {
            if (! Schema::hasColumn('evidence', 'request_fingerprint')) {
                $table->string('request_fingerprint', 64)->nullable()->after('type');
            }

            if (! Schema::hasColumn('evidence', 'fresh_until')) {
                $table->dateTime('fresh_until')->nullable()->after('observed_at');
            }
        });

        Schema::table('evidence', function (Blueprint $table): void {
            if (! $this->hasIndexNamed('evidence_request_fingerprint_index')) {
                $table->index('request_fingerprint');
            }

            if (! $this->hasIndexNamed('evidence_fresh_until_index')) {
                $table->index('fresh_until');
            }

            if (! $this->hasIndexNamed('evidence_fingerprint_fresh_lookup_index')) {
                $table->index(['request_fingerprint', 'fresh_until'], 'evidence_fingerprint_fresh_lookup_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evidence')) {
            return;
        }

        Schema::table('evidence', function (Blueprint $table): void {
            if ($this->hasIndexNamed('evidence_fingerprint_fresh_lookup_index')) {
                $table->dropIndex('evidence_fingerprint_fresh_lookup_index');
            }

            if ($this->hasIndexNamed('evidence_fresh_until_index')) {
                $table->dropIndex(['fresh_until']);
            }

            if ($this->hasIndexNamed('evidence_request_fingerprint_index')) {
                $table->dropIndex(['request_fingerprint']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('evidence', 'request_fingerprint') ? 'request_fingerprint' : null,
                Schema::hasColumn('evidence', 'fresh_until') ? 'fresh_until' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list(evidence)');

            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, 'evidence', $indexName],
        );

        return $rows !== [];
    }
};
