<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalOperationalDocsRouteTest extends TestCase
{
    /**
     * Current/canonical operator runbooks and architecture docs that must not
     * teach retired `/app` or `/system` URLs as live (ADR-044). Historical
     * ADR/QA snapshots are excluded; they must be marked superseded instead.
     *
     * @return list<string>
     */
    private function operationalDocs(): array
    {
        return [
            'AGENTS.md',
            'OPERATOR_ASYNC_EXECUTION.md',
            'PRODUCT_CAPABILITY_LEDGER.md',
            'PROJECT_MEMORY.md',
            'README.md',
            'THIRD_PARTY_NOTICES.md',
            'docs/MASTER_SPEC.md',
            'docs/architecture/CAPABILITY_REALITY_CONTRACT.md',
            'docs/architecture/DEMO_ISOLATION_CONTRACT.md',
            'docs/architecture/PERMISSION_BOUNDARY_CONTRACT.md',
            'docs/deployment/BACKUP_RESTORE.md',
            'docs/deployment/STAGING_ARCHITECTURE.md',
            'docs/foundation/CORE_RESPONSIBILITIES.md',
            'docs/foundation/PRODUCT_VISION.md',
            'docs/foundation/TERMINOLOGY.md',
            'docs/implementation/CORE_BOOTSTRAP.md',
            'docs/implementation/CURSOR_CLOUD_ENVIRONMENT.md',
            'docs/operations/PERSISTENT_UAT.md',
            'docs/production/FIRST_CUSTOMER_RUNBOOK.md',
            'docs/production/GO_LIVE_CHECKLIST.md',
            'docs/production/PRODUCTION_BLOCKERS.md',
            'docs/production/RELEASE_SMOKE_TESTS.md',
            'docs/production/ROLLBACK_RUNBOOK.md',
            'docs/reality/FINAL_CAPABILITY_REALITY_MATRIX.md',
            'docs/reality/REMAINING_PRODUCTION_GAPS.md',
        ];
    }

    public function test_operational_docs_do_not_instruct_retired_app_or_system_routes(): void
    {
        foreach ($this->operationalDocs() as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, $relative);

            $lines = explode("\n", (string) file_get_contents($path));
            foreach ($lines as $index => $line) {
                if (! $this->lineTeachesRetiredLiveRoute($line)) {
                    continue;
                }

                $this->fail(sprintf(
                    '%s:%d teaches a retired /app or /system URL as live (ADR-044). Mention 410/legacy/retired/superseded on the same line, or use root routes + /admin. Line: %s',
                    $relative,
                    $index + 1,
                    $line,
                ));
            }
        }
    }

    public function test_operator_activity_center_is_the_root_activity_route(): void
    {
        $async = (string) file_get_contents(base_path('OPERATOR_ASYNC_EXECUTION.md'));

        $this->assertStringContainsString('/activity', $async);
        $this->assertStringContainsString('operator.activity', $async);
        $this->assertMatchesRegularExpression('/Legacy `\/app\/runs` returns HTTP 410/', $async);
    }

    private function lineTeachesRetiredLiveRoute(string $line): bool
    {
        if (str_contains($line, 'storage/app')) {
            $withoutStorage = preg_replace('#storage/app(?:/[A-Za-z0-9._-]*)?#', '', $line) ?? $line;
            $line = $withoutStorage;
        }

        if (! preg_match('#(?<![A-Za-z0-9])/app(?:/|`|\s|$)|(?<![A-Za-z0-9])/system(?:/|`|\s|$)#', $line)) {
            return false;
        }

        return ! preg_match('/410|retired|legacy|superseded|historical|ADR-044|no longer|not a live/i', $line);
    }
}
