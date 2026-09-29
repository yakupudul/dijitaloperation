<?php

namespace Tests\Feature\Prompts;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Ai\Agents\BrandCandidateAgent;
use App\Ai\Agents\BrandServiceAgent;
use App\Ai\Agents\GbpPostFromPageAgent;
use App\Ai\Agents\MetaCreativesAgent;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\PromptVersion;
use App\Models\User;
use App\Services\Ai\AiRouteResolver;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 8 "Promptlar": registry versioning, rendering, guard sentence, agents wired, usage records, settings screen. */
final class PromptRegistryTest extends TestCase
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
        $this->registry->register('test.op', [
            'purpose' => 'Test işlemi.',
            'variables' => ['brand', 'items'],
            'context_sources' => ['Marka'],
            'output_schema' => ['type' => 'object'],
            'template' => 'Brand: {{brand}} Items: {{ items }}',
        ]);
    }

    public function test_first_use_stores_the_code_default_as_version_one_with_the_guard_sentence(): void
    {
        $this->assertSame(0, PromptVersion::query()->count());

        $version = $this->registry->current('test.op');

        $this->assertSame(1, $version->version);
        $this->assertTrue($version->is_current);
        $this->assertNull($version->created_by);
        $this->assertSame('Test işlemi.', $version->purpose);
        $this->assertSame(['brand', 'items'], $version->variables);
        $this->assertSame(['Marka'], $version->context_sources);
        $this->assertStringStartsWith('Brand: {{brand}}', $version->template);
        $this->assertStringEndsWith(PromptRegistry::GUARD, $version->template);
        $this->assertSame($version->id, $this->registry->current('test.op')->id, 'second use reads, never re-creates');
        $this->assertSame(1, PromptVersion::query()->count());
    }

    public function test_render_substitutes_variables_and_unknown_variables_fail_in_tests(): void
    {
        $text = $this->registry->render('test.op', ['brand' => 'Panorama', 'items' => ['a', 'b']]);

        $this->assertStringStartsWith('Brand: Panorama Items: ["a","b"]', $text);
        $this->assertStringEndsWith(PromptRegistry::GUARD, $text);

        $this->expectException(InvalidArgumentException::class);
        $this->registry->render('test.op', ['brand' => 'Panorama']);
    }

    public function test_publish_creates_a_new_current_version_and_revert_copies_an_old_one(): void
    {
        $first = $this->registry->current('test.op');

        $second = $this->registry->publish('test.op', ['template' => 'Yeni {{brand}}', 'model' => 'anthropic:pinned-model'], $this->admin);

        $this->assertSame(2, $second->version);
        $this->assertTrue($second->is_current);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($this->admin->id, $second->created_by);
        $this->assertSame('anthropic:pinned-model', $second->model);
        $this->assertSame(['anthropic', 'pinned-model'], $this->registry->modelFor('test.op'));
        $this->assertStringContainsString(PromptRegistry::GUARD, $second->template, 'guard appended when the operator leaves it out');

        $third = $this->registry->revert('test.op', 1, $this->admin);

        $this->assertSame(3, $third->version);
        $this->assertSame($first->template, $third->template);
        $this->assertNull($third->model);
        $this->assertSame($third->id, $this->registry->current('test.op')->id);
        $this->assertSame(1, PromptVersion::query()->where('operation', 'test.op')->where('is_current', true)->count());
    }

    public function test_publish_rejects_undeclared_variables_and_non_admins(): void
    {
        try {
            $this->registry->publish('test.op', ['template' => 'X {{secret}}'], $this->admin);
            $this->fail('undeclared variable accepted');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('secret', $exception->getMessage());
        }

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->expectException(AuthorizationException::class);
        $this->registry->publish('test.op', ['template' => 'X'], $operator);
    }

    public function test_agents_take_their_instructions_from_the_registry(): void
    {
        $default = (string) config('moxdop-prompts.operations')[AiRouteKeys::BRAND_CANDIDATES]['template'];
        $this->assertSame($default."\n\n".PromptRegistry::GUARD, (string) (new BrandCandidateAgent)->instructions(), 'default = current text + guard');

        foreach ([
            AiRouteKeys::GBP_POST_FROM_PAGE => GbpPostFromPageAgent::class,
            AiRouteKeys::BRAND_SERVICES => BrandServiceAgent::class,
            AiRouteKeys::META_CREATIVES => MetaCreativesAgent::class,
        ] as $operation => $agent) {
            $version = $this->registry->publish($operation, ['template' => 'Operatör şablonu '.$operation], $this->admin);
            $instance = new $agent;
            $this->assertStringStartsWith('Operatör şablonu '.$operation, (string) $instance->instructions());
            $this->assertSame($version->id, $instance->promptVersionId());
        }

        $analyst = new ChannelAnalystAgent('analyst.search', 'Kanal talimatı.', ['content', 'fix']);
        $this->registry->register('analyst.search', (array) config('moxdop-prompts.channel_analyst'));
        $text = (string) $analyst->instructions();
        $this->assertStringStartsWith('Kanal talimatı.', $text);
        $this->assertStringContainsString("\nAllowed action types: content, fix.", $text);
        $this->assertStringEndsWith(PromptRegistry::GUARD, $text);
    }

    public function test_usage_records_carry_the_prompt_version_and_duration(): void
    {
        config(['ai.providers.anthropic.key' => 'configured']);
        GbpPostFromPageAgent::fake([['text' => 'Metin', 'action_type' => 'BOOK']]);
        $version = $this->registry->publish(AiRouteKeys::GBP_POST_FROM_PAGE, ['template' => 'Gönderi yaz.'], $this->admin);

        (new GbpPostFromPageAgent)->prompt('CONTEXT_JSON {}', provider: 'anthropic');

        $row = DB::table('ai_usage_records')->first();
        $this->assertNotNull($row);
        $this->assertSame($version->id, (int) $row->prompt_version_id);
        $this->assertSame('ok', $row->status);
        $this->assertNotNull($row->duration_ms);
    }

    public function test_model_pinned_on_the_prompt_version_becomes_the_primary_route_step(): void
    {
        config(['moxdop.anthropic.api_key' => 'configured']);
        $routes = app(AiRouteResolver::class);
        $this->assertSame(AiProviderCatalog::defaultModel('anthropic'), $routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY)->primaryModel());

        $this->registry->publish(AiRouteKeys::GBP_REVIEW_REPLY, ['template' => 'Yanıt yaz.', 'model' => 'anthropic:pinned-model'], $this->admin);

        $route = $routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY);
        $this->assertSame('anthropic', $route->primaryProvider());
        $this->assertSame('pinned-model', $route->primaryModel());
    }

    public function test_settings_screen_lists_operations_publishes_and_reverts(): void
    {
        $version = $this->registry->current(AiRouteKeys::GBP_REVIEW_REPLY);
        DB::table('ai_usage_records')->insert([
            'route_key' => AiRouteKeys::GBP_REVIEW_REPLY, 'prompt_version_id' => $version->id, 'agent' => 'ReviewReplyAgent',
            'provider' => 'anthropic', 'model' => 'test-model', 'cost_usd' => 0.25, 'duration_ms' => 4000, 'status' => 'ok', 'created_at' => now()->subDay(),
        ]);
        $this->actingAs($this->admin);

        $this->get(route('operator.settings.ai-operations'))->assertOk()->assertSee(AiRouteKeys::GBP_REVIEW_REPLY)->assertSee('4,0 sn');

        Livewire::test(AiOperationsPage::class)
            ->call('open', AiRouteKeys::GBP_REVIEW_REPLY)
            ->assertSee('Sürüm geçmişi')
            ->assertSee('Son çalıştırmalar')
            ->assertSee('Başarılı')
            ->set('template', 'Kısa ve kibar yanıt yaz.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Sürüm 2 yayınlandı.')
            ->assertSee('Yönetici')
            ->call('revertTo', 1)
            ->assertSee('Sürüm 1 geri yüklendi (yeni sürüm 3).');

        $current = $this->registry->current(AiRouteKeys::GBP_REVIEW_REPLY);
        $this->assertSame(3, $current->version);
        $this->assertSame($version->template, $current->template);

        Livewire::test(AiOperationsPage::class)->call('open', AiRouteKeys::GBP_REVIEW_REPLY)
            ->set('template', 'X {{bilinmeyen}}')->call('save')->assertHasErrors('template');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($operator)->get(route('operator.settings.ai-operations'))->assertForbidden();
    }
}
