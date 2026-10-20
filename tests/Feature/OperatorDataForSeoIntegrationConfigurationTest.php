<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Models\User;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorDataForSeoIntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'dfs-test-password-002b';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_hub_review_self_link_is_replaced_with_configure_workspace(): void
    {
        $card = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $group) => $group['providers'])
            ->firstWhere('id', ProviderRegistry::DATAFORSEO);

        $this->assertSame('operator.integrations.dataforseo', $card['route']);
        $this->assertSame('Configure', $card['manage_label']);
        $this->assertSame('Not configured', $card['state_label']);

        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertDontSee('DataForSEO') // hidden from the hub 2026-10-19: all AI runs on the Claude MCP queue, DataForSEO is never added; the configure route below still works
            ->assertDontSeeHtml('>Review<');

        $this->get(route('operator.integrations.dataforseo'))->assertStatus(410); // settings page removed 2026-10-20
    }
}
