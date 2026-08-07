<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Findings\Pages\ListFindings;
use App\Filament\App\Resources\Findings\Pages\ViewFinding;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FindingsResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->actingAs($this->admin);

        Filament::setCurrentPanel('app');
    }

    public function test_admin_can_access_findings_list_and_see_expected_columns(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'fingerprint' => 'fp-list-test-aaaaaaaaaaaaaaaa',
            'category' => 'performance',
            'severity' => 'high',
            'title' => 'Largest Contentful Paint is poor',
            'summary' => 'LCP exceeds the recommended threshold on the landing page.',
            'confidence' => 0.9100,
            'status' => 'open',
        ]);

        Livewire::test(ListFindings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$finding])
            ->assertSee('Acme Corporate Website')
            ->assertSee('website')
            ->assertSee('fp-list-test-aaaa')
            ->assertSee('performance')
            ->assertSee('high')
            ->assertSee('Largest Contentful Paint is poor')
            ->assertSee('0.9100')
            ->assertSee('open');
    }

    public function test_admin_can_access_finding_view_and_see_summary_last_run_and_recommendations(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'fingerprint' => 'fp-view-test-bbbbbbbbbbbbbbbb',
            'category' => 'performance',
            'severity' => 'critical',
            'title' => 'Server response time is elevated',
            'summary' => 'Full summary: TTFB consistently exceeds 800ms on primary landing routes.',
            'confidence' => 0.8750,
            'status' => 'acknowledged',
            'last_run_id' => $run->id,
        ]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Enable edge caching for landing HTML',
            'priority' => 'high',
            'status' => 'open',
        ]);

        Livewire::test(ViewFinding::class, [
            'record' => $finding->getRouteKey(),
        ])
            ->assertOk()
            ->assertSchemaStateSet([
                'id' => $finding->id,
                'digitalAsset.name' => 'Acme Corporate Website',
                'source_module' => 'website',
                'fingerprint' => 'fp-view-test-bbbbbbbbbbbbbbbb',
                'category' => 'performance',
                'severity' => 'critical',
                'title' => 'Server response time is elevated',
                'summary' => 'Full summary: TTFB consistently exceeds 800ms on primary landing routes.',
                'confidence' => '0.8750',
                'status' => 'acknowledged',
                'last_run_id' => $run->id,
            ])
            ->assertSee("Run #{$run->id}")
            ->assertSee("Recommendation #{$recommendation->id}")
            ->assertSee('Enable edge caching for landing HTML');
    }

    public function test_findings_list_supports_digital_asset_and_status_filters(): void
    {
        $assetA = DigitalAsset::factory()->create(['name' => 'Asset Alpha']);
        $assetB = DigitalAsset::factory()->create(['name' => 'Asset Beta']);

        $openFinding = Finding::factory()->create([
            'digital_asset_id' => $assetA->id,
            'status' => 'open',
            'title' => 'Open finding on Alpha',
        ]);

        $resolvedFinding = Finding::factory()->create([
            'digital_asset_id' => $assetB->id,
            'status' => 'resolved',
            'title' => 'Resolved finding on Beta',
        ]);

        Livewire::test(ListFindings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$openFinding, $resolvedFinding])
            ->filterTable('status', 'open')
            ->assertCanSeeTableRecords([$openFinding])
            ->assertCanNotSeeTableRecords([$resolvedFinding])
            ->filterTable('status')
            ->filterTable('digital_asset_id', (string) $assetB->id)
            ->assertCanSeeTableRecords([$resolvedFinding])
            ->assertCanNotSeeTableRecords([$openFinding]);
    }
}
