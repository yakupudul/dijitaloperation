<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RunResourceTest extends TestCase
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

    public function test_admin_can_access_runs_list_and_see_expected_columns(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'name' => 'Primary WordPress',
            'type' => 'wordpress',
        ]);

        $startedAt = now()->subHour()->startOfSecond();
        $finishedAt = now()->subMinutes(10)->startOfSecond();

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => 'website',
            'status' => 'completed',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        Livewire::test(ListRuns::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$run])
            ->assertSee('Acme Corporate Website')
            ->assertSee('Website')
            ->assertSee('completed')
            ->assertDontSee('Primary WordPress');
    }

    public function test_admin_can_access_run_view_and_see_pretty_printed_evidence_json_without_credentials(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'name' => 'Primary WordPress',
            'type' => 'wordpress',
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'api_token' => 'super-secret-token-should-never-appear',
                'password' => 'credential-password-must-stay-hidden',
            ],
        ]);

        $evidence = [
            [
                'type' => 'pagespeed.metrics',
                'title' => 'LCP observation',
                'payload' => [
                    'lcp_ms' => 4200,
                    'url' => 'https://example.com/',
                ],
            ],
        ];

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => 'website',
            'status' => 'completed',
            'metadata' => [
                'trigger' => 'manual',
                'attempt' => 1,
                'evidence' => $evidence,
            ],
        ]);

        Livewire::test(ViewRun::class, [
            'record' => $run->getRouteKey(),
        ])
            ->assertOk()
            ->assertSchemaStateSet([
                'id' => $run->id,
                'digitalAsset.name' => 'Acme Corporate Website',
                'coreConnection.name' => 'Primary WordPress',
                'status' => 'completed',
                'module_id' => 'website',
            ])
            ->assertSee('pagespeed.metrics')
            ->assertSee('lcp_ms')
            ->assertSee('4200')
            ->assertSee('https://example.com/')
            ->assertDontSee('super-secret-token-should-never-appear')
            ->assertDontSee('credential-password-must-stay-hidden')
            ->assertDontSee('encrypted_payload');
    }
}
