<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalOperationalDocsRouteTest extends TestCase
{
    /**
     * Live Google implementation specs that PRODUCT_CAPABILITY_LEDGER.md cites
     * as current (not historical prompt archives).
     *
     * @return list<string>
     */
    private function liveLedgerLinkedGoogleSpecs(): array
    {
        return [
            'docs/implementation/GOOGLE_OAUTH_CREDENTIAL_LIFECYCLE.md',
            'docs/implementation/GOOGLE_RESOURCE_DISCOVERY.md',
            'docs/implementation/GOOGLE_RESOURCE_SELECTION_BINDING.md',
        ];
    }

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

    /**
     * @return list<string>
     */
    private function docsThatMustNotTeachRetiredLiveRoutes(): array
    {
        $docs = array_merge(
            $this->operationalDocs(),
            $this->liveLedgerLinkedImplementationDocs(),
        );

        sort($docs);

        return array_values(array_unique($docs));
    }

    /**
     * Implementation docs cited by PRODUCT_CAPABILITY_LEDGER.md as current
     * capability sources. Historical prompt archives (ADR-044 banner in the
     * file head) are skipped so frozen `/app` labels are not rewritten blindly.
     *
     * @return list<string>
     */
    private function liveLedgerLinkedImplementationDocs(): array
    {
        $ledger = (string) file_get_contents(base_path('PRODUCT_CAPABILITY_LEDGER.md'));
        preg_match_all('/docs:\s*`([^`]+)`/i', $ledger, $matches);

        $paths = [];
        foreach ($matches[1] as $ref) {
            $relative = $this->resolveLedgerDocPath($ref);
            if ($relative === null) {
                continue;
            }
            if ($this->isMarkedHistoricalArchive($relative)) {
                continue;
            }
            $paths[] = $relative;
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    private function resolveLedgerDocPath(string $ref): ?string
    {
        $ref = ltrim($ref, '/');
        $candidates = [
            $ref,
            'docs/implementation/'.basename($ref),
            'docs/'.basename($ref),
            basename($ref),
        ];

        foreach ($candidates as $candidate) {
            if (is_file(base_path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function isMarkedHistoricalArchive(string $relative): bool
    {
        $path = base_path($relative);
        if (! is_file($path)) {
            return false;
        }

        $head = implode("\n", array_slice(explode("\n", (string) file_get_contents($path)), 0, 12));

        return (bool) preg_match('/ADR-044/', $head)
            && (bool) preg_match('/historical|superseded/i', $head)
            && (bool) preg_match('/410/', $head);
    }

    public function test_operational_docs_do_not_instruct_retired_app_or_system_routes(): void
    {
        $docs = $this->docsThatMustNotTeachRetiredLiveRoutes();

        foreach ($this->liveLedgerLinkedGoogleSpecs() as $googleSpec) {
            $this->assertContains(
                $googleSpec,
                $docs,
                $googleSpec.' must be scanned because PRODUCT_CAPABILITY_LEDGER.md cites it as a live implementation spec.',
            );
            $this->assertFalse(
                $this->isMarkedHistoricalArchive($googleSpec),
                $googleSpec.' is a live capability spec and must not be skipped as a historical archive.',
            );
        }

        $this->assertTrue(
            $this->isMarkedHistoricalArchive('docs/implementation/GOOGLE_INTEGRATION_ARCHITECTURE.md'),
            'GOOGLE_INTEGRATION_ARCHITECTURE.md must keep an ADR-044 historical banner so frozen /app labels are not treated as live.',
        );
        $this->assertNotContains(
            'docs/implementation/GOOGLE_INTEGRATION_ARCHITECTURE.md',
            $docs,
            'Historical prompt archives must not be blindly failed by the live-route guard.',
        );

        foreach ($docs as $relative) {
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

    public function test_ledger_linked_live_google_specs_declare_root_integrations_surface(): void
    {
        foreach ($this->liveLedgerLinkedGoogleSpecs() as $relative) {
            $contents = (string) file_get_contents(base_path($relative));

            $this->assertMatchesRegularExpression(
                '/\*\*Canonical surface:\*\* `\/integrations`/',
                $contents,
                $relative.' must declare the root /integrations operator surface (ADR-044).',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\*\*Canonical surface:\*\* `\/app\//',
                $contents,
                $relative.' must not keep /app/integrations as the canonical surface.',
            );
        }
    }

    public function test_operator_activity_center_is_the_root_activity_route(): void
    {
        $async = (string) file_get_contents(base_path('OPERATOR_ASYNC_EXECUTION.md'));

        $this->assertStringContainsString('/activity', $async);
        $this->assertStringContainsString('operator.activity', $async);
        $this->assertMatchesRegularExpression('/Legacy `\/app\/runs` returns HTTP 410/', $async);

        $ledger = (string) file_get_contents(base_path('PRODUCT_CAPABILITY_LEDGER.md'));
        $this->assertMatchesRegularExpression(
            '/Operator Activity Center is the root Livewire surface `\/activity` \(`operator\.activity`\)/',
            $ledger,
        );
        $this->assertMatchesRegularExpression(
            '/Filament `RunResource` at `\/admin\/runs` remains technical\/admin tooling only/',
            $ledger,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Activity Center is Filament `RunResource` \(`\/admin\/runs`\)/',
            $ledger,
        );
    }

    public function test_retired_paths_inside_absolute_urls_are_detected(): void
    {
        $this->assertTrue($this->lineTeachesRetiredLiveRoute('Open https://host/app/login'));
        $this->assertTrue($this->lineTeachesRetiredLiveRoute('Open http://127.0.0.1/system/settings'));
        $this->assertTrue($this->lineTeachesRetiredLiveRoute('Open http://127.0.0.1:8000/app/customers'));
        $this->assertFalse($this->lineTeachesRetiredLiveRoute('Legacy `https://host/app/login` returns HTTP 410'));
        $this->assertFalse($this->lineTeachesRetiredLiveRoute('Historical snapshot: http://127.0.0.1:8000/app/activity (ADR-044)'));
        $this->assertFalse($this->lineTeachesRetiredLiveRoute('Operator login is `/login`'));
        $this->assertFalse($this->lineTeachesRetiredLiveRoute('Files live in storage/app/private'));
        $this->assertFalse($this->lineTeachesRetiredLiveRoute('Filament technical login is `/admin/login`'));
    }

    private function lineTeachesRetiredLiveRoute(string $line): bool
    {
        if (str_contains($line, 'storage/app')) {
            $withoutStorage = preg_replace('#storage/app(?:/[A-Za-z0-9._-]*)?#', '', $line) ?? $line;
            $line = $withoutStorage;
        }

        // Hostnames are alphanumeric, so `/app` in `https://host/app/...` would
        // otherwise fail the path lookbehind. Strip the origin first.
        $line = preg_replace('#https?://[^\s/`\'"<>]+#i', '', $line) ?? $line;

        if (! preg_match('#(?<![A-Za-z0-9])/app(?:/|`|\s|$)|(?<![A-Za-z0-9])/system(?:/|`|\s|$)#', $line)) {
            return false;
        }

        return ! preg_match('/410|retired|legacy|superseded|historical|ADR-044|no longer|not a live/i', $line);
    }
}
