<?php

namespace Tests\Feature\Prompts;

use App\Livewire\Operator\AiPromptInfo;
use App\Livewire\Operator\Library\QueryPlanWizard;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\User;
use App\Services\Prompts\PromptRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ⓘ next to AI action buttons: shows the operation's prompt; Admin publishes an edited version or goes back to the
 * code default through the prompt registry.
 */
final class AiPromptInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PromptRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yönetici']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->registry = app(PromptRegistry::class);
    }

    public function test_info_window_shows_the_current_prompt_and_admin_edits_and_resets_it(): void
    {
        $this->actingAs($this->admin);
        $default = $this->registry->current('queries.plan_services');

        $window = Livewire::test(AiPromptInfo::class)
            ->dispatch('ai-prompt-info', operation: 'queries.plan_services')
            ->assertSee('AI ile planla · hizmetler')
            ->assertSee($default->purpose)
            ->assertSee('Sürüm v'.$default->version)
            ->assertSee('Varsayılan (kod)')
            ->assertSee(mb_substr((string) $default->template, 0, 60))
            ->assertSee('Düzenle')
            ->assertDontSee('Varsayılana dön')
            ->call('edit')
            ->assertSet('editing', true)
            ->set('template', 'Sektör başına eksik hizmetleri öner.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertDispatched('operator-notice')
            ->assertSee('Düzenlenmiş · Yönetici')
            ->assertSee('Sektör başına eksik hizmetleri öner.');

        $edited = $this->registry->current('queries.plan_services');
        $this->assertSame($default->version + 1, $edited->version);
        $this->assertSame($this->admin->id, $edited->created_by);
        $this->assertFalse($this->registry->isDefault($edited));

        $window->call('resetDefault')->assertSee('Varsayılan (kod)');
        $reset = $this->registry->current('queries.plan_services');
        $this->assertSame($default->version + 2, $reset->version);
        $this->assertSame($default->template, $reset->template);
        $this->assertTrue($this->registry->isDefault($reset));
    }

    public function test_settings_screen_goes_back_to_the_code_default(): void
    {
        $this->actingAs($this->admin);
        $this->registry->publish('queries.cluster', ['template' => 'Özel kümeleme promptu.'], $this->admin);

        Livewire::test(AiOperationsPage::class)
            ->call('open', 'queries.cluster')
            ->call('resetDefault')
            ->assertSee('Varsayılan prompt geri yüklendi (yeni sürüm 3).');

        $this->assertTrue($this->registry->isDefault($this->registry->current('queries.cluster')));
    }

    public function test_invalid_template_is_rejected_in_the_window(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(AiPromptInfo::class)
            ->call('show', 'queries.plan_services')
            ->call('edit')
            ->set('template', 'X {{bilinmeyen}}')
            ->call('save')
            ->assertHasErrors('template');

        $this->assertSame(1, $this->registry->current('queries.plan_services')->version);
    }

    public function test_non_admin_sees_the_prompt_but_cannot_edit(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($operator);

        $window = Livewire::test(AiPromptInfo::class)
            ->dispatch('ai-prompt-info', operation: 'queries.plan_filters')
            ->assertSee('AI ile planla · filtreler')
            ->assertSee('Promptu yalnız Admin düzenler.')
            ->assertDontSee('Düzenle</button>', false);
        $window->call('edit')->assertForbidden();
        Livewire::test(AiPromptInfo::class)->call('show', 'queries.plan_filters')->set('template', 'Değiştirilmiş.')->call('save')->assertForbidden();
        Livewire::test(AiPromptInfo::class)->call('show', 'queries.plan_filters')->call('resetDefault')->assertForbidden();

        $this->assertSame(1, $this->registry->current('queries.plan_filters')->version);
    }

    public function test_unknown_operation_opens_nothing(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(AiPromptInfo::class)->dispatch('ai-prompt-info', operation: 'yok.boyle')->assertSet('operation', '')->assertDontSee('Sürüm v');
    }

    public function test_info_button_dispatches_the_operation(): void
    {
        $html = Blade::render('<x-operator.ai-prompt-info operation="queries.cluster" />');

        $this->assertStringContainsString('data-ai-prompt-info="queries.cluster"', $html);
        $this->assertStringContainsString("Livewire.dispatch('ai-prompt-info', { operation: 'queries.cluster' })", $html);
    }

    public function test_query_plan_wizard_has_the_info_button_on_every_ai_step(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(QueryPlanWizard::class)
            ->assertSeeHtml('data-ai-prompt-info="queries.plan_sectors"')
            ->call('goTo', 2)
            ->assertSeeHtml('data-ai-prompt-info="queries.plan_services"')
            ->call('goTo', 3)
            ->assertSeeHtml('data-ai-prompt-info="queries.plan_filters"');
    }
}
