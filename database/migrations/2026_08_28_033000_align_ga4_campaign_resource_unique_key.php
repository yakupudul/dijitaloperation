<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ga4_campaign_daily';

    private const RESOURCE_INDEX = 'ga4_campaign_daily_resource_nk_unique';

    /**
     * GA4 campaign grain was expanded to include resource scope, campaign id,
     * source and medium. The legacy unique constraint on
     * (digital_asset_id, property_id, reporting_date, sessionCampaignName)
     * remained on the partitioned table and rejected legitimate reconciliation
     * rows before the canonical resource-key ON CONFLICT clause could run.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([
            'external_resource_id',
            'property_id',
            'reporting_date',
            'sessionCampaignId',
            'sessionCampaignName',
            'sessionSource',
            'sessionMedium',
        ] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return;
            }
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->alignPostgres();

            return;
        }

        $this->alignPortableDatabase();
    }

    private function alignPostgres(): void
    {
        // Ensure the canonical expanded-grain unique index exists first.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "'.self::RESOURCE_INDEX.'" '
            .'ON "'.self::TABLE.'" '
            .'("external_resource_id", "property_id", "reporting_date", "sessionCampaignId", "sessionCampaignName", "sessionSource", "sessionMedium")'
        );

        // The original table was created with an unnamed UNIQUE constraint.
        // Discover it by definition instead of relying on PostgreSQL's
        // truncated/generated constraint name. Dropping it on the partitioned
        // parent also removes its partition child constraints/indexes.
        $constraints = DB::select(<<<'SQL'
            SELECT con.conname, pg_get_constraintdef(con.oid) AS definition
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
            WHERE nsp.nspname = current_schema()
              AND rel.relname = 'ga4_campaign_daily'
              AND con.contype = 'u'
        SQL);

        foreach ($constraints as $constraint) {
            $definition = (string) ($constraint->definition ?? '');
            $name = (string) ($constraint->conname ?? '');

            if ($name === '' || ! $this->isLegacyConstraintDefinition($definition)) {
                continue;
            }

            DB::statement(
                'ALTER TABLE "'.self::TABLE.'" DROP CONSTRAINT IF EXISTS "'.str_replace('"', '""', $name).'"'
            );
        }
    }

    private function alignPortableDatabase(): void
    {
        // The initial non-PostgreSQL migration used this explicit name.
        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('ga4_campaign_daily_nk_unique');
            });
        } catch (Throwable) {
            // Already absent is the desired state.
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    [
                        'external_resource_id',
                        'property_id',
                        'reporting_date',
                        'sessionCampaignId',
                        'sessionCampaignName',
                        'sessionSource',
                        'sessionMedium',
                    ],
                    self::RESOURCE_INDEX,
                );
            });
        } catch (Throwable) {
            // Existing equivalent resource-key index is acceptable.
        }
    }

    private function isLegacyConstraintDefinition(string $definition): bool
    {
        if (! str_starts_with(strtoupper(trim($definition)), 'UNIQUE')) {
            return false;
        }

        foreach (['digital_asset_id', 'property_id', 'reporting_date', 'sessionCampaignName'] as $column) {
            if (! str_contains($definition, $column)) {
                return false;
            }
        }

        // Never remove the new expanded resource-key constraint/index.
        foreach (['external_resource_id', 'sessionCampaignId', 'sessionSource', 'sessionMedium'] as $expandedColumn) {
            if (str_contains($definition, $expandedColumn)) {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        // Intentionally irreversible. Reintroducing the legacy unique key would
        // make valid expanded-grain campaign rows conflict and could break
        // reconciliation again.
    }
};
