<?php

namespace Tests\Feature\Playbooks;

use App\Enums\PlaybookApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Enums\TaskScopeKind;
use App\Exceptions\PlaybookValidationException;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\QaReview;
use App\Models\Recommendation;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\Playbooks\PlaybookApplicabilityResolver;
use App\Services\Playbooks\PlaybookReadService;
use App\Services\Playbooks\PlaybookService;
use App\Services\Playbooks\SeedDefaultPlaybooks;
use App\Services\Tasks\CreateDirectTask;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlaybooksProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Roles::ADMIN);
        $this->actingAs($this->actor);

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id]);

        Http::fake();
    }

    public function test_canonical_tables_exist_without_v2_or_works(): void
    {
        $this->assertTrue(Schema::hasTable('playbooks'));
        $this->assertTrue(Schema::hasTable('playbook_revisions'));
        $this->assertTrue(Schema::hasTable('playbook_instructions'));
        $this->assertTrue(Schema::hasTable('playbook_references'));
        $this->assertFalse(Schema::hasTable('playbooks_v2'));
        $this->assertFalse(Schema::hasTable('works'));
    }

    public function test_default_seed_is_idempotent_and_preserves_operator_edits(): void
    {
        $seeder = app(SeedDefaultPlaybooks::class);
        $first = $seeder->seed($this->actor);
        $second = $seeder->seed($this->actor);

        $this->assertSame(4, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(4, $second['skipped']);

        $playbook = Playbook::query()->where('stable_key', 'pb-weekly-gads')->firstOrFail();
        $beforeRevision = $playbook->current_revision_id;

        app(PlaybookService::class)->revise($playbook, [
            'title' => 'Weekly Google Ads Review (agency edition)',
            'summary' => 'Operator-edited summary',
            'knowledge' => ['purpose' => 'Edited purpose'],
            'cadence' => 'weekly',
            'service_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Explicit->value,
            'service_definition_ids' => [
                ServiceDefinition::query()->where('code', 'google_ads')->value('id'),
            ],
            'asset_types' => ['google_ads'],
            'execution_scopes' => ['digital_asset'],
            'instructions' => [['body' => 'Operator step']],
            'references' => [],
        ], $this->actor, 'revise:gads:1');

        $seeder->seed($this->actor);
        $playbook->refresh();
        $this->assertNotSame($beforeRevision, $playbook->current_revision_id);
        $this->assertSame('Weekly Google Ads Review (agency edition)', $playbook->currentRevision?->title);
        $this->assertSame(2, PlaybookRevision::query()->where('playbook_id', $playbook->id)->count());
    }

    public function test_rename_preserves_identity_and_revision_history(): void
    {
        $playbook = $this->createAnyPlaybook('Original title');
        $id = $playbook->id;
        $rev1 = $playbook->current_revision_id;

        app(PlaybookService::class)->revise($playbook, $this->anyPayload([
            'title' => 'Renamed title',
            'instructions' => [['body' => 'Updated step']],
        ]), $this->actor, 'rename:1', $rev1);

        $playbook->refresh();
        $this->assertSame($id, $playbook->id);
        $this->assertSame('Renamed title', $playbook->currentRevision?->title);
        $this->assertSame(2, $playbook->revisions()->count());
        $this->assertSame('Original title', PlaybookRevision::query()->find($rev1)?->title);
    }

    public function test_same_title_on_two_playbooks_allowed(): void
    {
        $a = $this->createAnyPlaybook('Shared title');
        $b = $this->createAnyPlaybook('Shared title');
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_revise_idempotency_and_concurrency(): void
    {
        $playbook = $this->createAnyPlaybook('Concurrency');
        $current = (int) $playbook->current_revision_id;

        $once = app(PlaybookService::class)->revise($playbook, $this->anyPayload([
            'title' => 'Concurrency v2',
            'instructions' => [['body' => 'A']],
        ]), $this->actor, 'idem:rev:1', $current);

        $again = app(PlaybookService::class)->revise($playbook, $this->anyPayload([
            'title' => 'Concurrency v2',
            'instructions' => [['body' => 'A']],
        ]), $this->actor, 'idem:rev:1', $current);

        $this->assertSame($once->current_revision_id, $again->current_revision_id);
        $this->assertSame(2, PlaybookRevision::query()->where('playbook_id', $playbook->id)->count());

        $this->expectException(PlaybookValidationException::class);
        app(PlaybookService::class)->revise($playbook, $this->anyPayload([
            'title' => 'stale base',
            'instructions' => [['body' => 'B']],
        ]), $this->actor, 'idem:rev:stale', $current);
    }

    public function test_explicit_service_and_asset_modes_and_url_safety(): void
    {
        $googleAds = ServiceDefinition::query()->where('code', 'google_ads')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(PlaybookService::class)->create([
            'title' => 'Bad',
            'service_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
            'service_definition_ids' => [],
            'instructions' => [['body' => 'x']],
            'references' => [
                ['kind' => 'external_url', 'label' => 'evil', 'url' => 'javascript:alert(1)'],
            ],
        ], $this->actor);
    }

    public function test_safe_https_reference_and_rejects_data_scheme(): void
    {
        $playbook = app(PlaybookService::class)->create($this->anyPayload([
            'title' => 'With link',
            'references' => [
                ['kind' => 'external_url', 'label' => 'Docs', 'url' => 'https://example.com/sop'],
            ],
        ]), $this->actor, 'ref:https');

        $this->assertDatabaseHas('playbook_references', [
            'playbook_revision_id' => $playbook->current_revision_id,
            'kind' => 'external_url',
            'url' => 'https://example.com/sop',
        ]);

        $this->expectException(ValidationException::class);
        app(PlaybookService::class)->revise($playbook, $this->anyPayload([
            'title' => 'With bad link',
            'references' => [
                ['kind' => 'external_url', 'label' => 'bad', 'url' => 'data:text/html,hi'],
            ],
        ]), $this->actor);
    }

    public function test_applicability_resolver_service_scope_and_no_first_fallback(): void
    {
        $service = ServiceDefinition::query()->where('code', 'google_ads')->firstOrFail();
        $playbook = app(PlaybookService::class)->create([
            'title' => 'Ads SOP',
            'service_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Explicit->value,
            'service_definition_ids' => [$service->id],
            'asset_types' => ['google_ads'],
            'execution_scopes' => ['digital_asset'],
            'instructions' => [['body' => 'Review spend']],
            'references' => [],
        ], $this->actor, 'app:1');

        $resolver = app(PlaybookApplicabilityResolver::class);
        $unknown = $resolver->resolve($playbook, $this->customer, null, null);
        $this->assertSame('SERVICE_SCOPE_UNKNOWN', $unknown['service_scope_context']);
        $this->assertContains('CUSTOMER_LEVEL_SCOPE_NOT_AGGREGATED', $unknown['reasons']);

        CustomerServiceScope::query()->create([
            'customer_id' => $this->customer->id,
            'service_definition_id' => $service->id,
            'status' => ServiceScopeStatus::Active->value,
            'brand_applicability_mode' => 'customer_wide',
        ]);

        $inScope = $resolver->resolve($playbook, null, $this->brand, null);
        $this->assertSame('IN_CURRENT_SCOPE', $inScope['service_scope_context']);

        $before = CustomerServiceScope::query()->count();
        $resolver->resolve($playbook, null, $this->brand, null);
        $this->assertSame($before, CustomerServiceScope::query()->count());
    }

    public function test_asset_compatibility_and_wordpress_dataforseo_not_types(): void
    {
        $service = ServiceDefinition::query()->where('code', 'website_maintenance')->firstOrFail();
        $playbook = app(PlaybookService::class)->create([
            'title' => 'Website SOP',
            'service_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
            'service_definition_ids' => [$service->id],
            'asset_types' => ['website'],
            'instructions' => [['body' => 'Check SSL']],
            'references' => [],
        ], $this->actor, 'asset:1');

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);
        $ads = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);

        $ok = app(PlaybookApplicabilityResolver::class)->resolve($playbook, null, $this->brand, null, $website);
        $this->assertTrue($ok['asset_type_compatible']);

        $bad = app(PlaybookApplicabilityResolver::class)->resolve($playbook, null, $this->brand, null, $ads);
        $this->assertFalse($bad['asset_type_compatible']);

        $this->expectException(ValidationException::class);
        app(PlaybookService::class)->create([
            'title' => 'Invalid asset',
            'service_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
            'asset_types' => ['wordpress'],
            'instructions' => [['body' => 'x']],
            'references' => [],
        ], $this->actor);
    }

    public function test_playbook_does_not_create_task_qa_approval_recommendation(): void
    {
        $tasks = Task::query()->count();
        $qa = QaReview::query()->count();
        $recs = Recommendation::query()->count();

        app(SeedDefaultPlaybooks::class)->seed($this->actor);
        $playbook = Playbook::query()->where('stable_key', 'pb-weekly-gads')->firstOrFail();
        app(PlaybookReadService::class)->findPresentation($playbook->stable_key);

        $this->assertSame($tasks, Task::query()->count());
        $this->assertSame($qa, QaReview::query()->count());
        $this->assertSame($recs, Recommendation::query()->count());
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_archive_and_read_list_excludes_archived_by_default(): void
    {
        $playbook = $this->createAnyPlaybook('Archive me');
        app(PlaybookService::class)->archive($playbook, $this->actor);
        $list = app(PlaybookReadService::class)->forList();
        $this->assertFalse(collect($list)->contains(fn (array $row): bool => $row['playbook_id'] === $playbook->id));

        app(PlaybookService::class)->restore($playbook, $this->actor);
        $list = app(PlaybookReadService::class)->forList();
        $this->assertTrue(collect($list)->contains(fn (array $row): bool => $row['playbook_id'] === $playbook->id));
    }

    public function test_provider_boundary_zero_http(): void
    {
        app(SeedDefaultPlaybooks::class)->seed($this->actor);
        app(PlaybookReadService::class)->forList();
        app(PlaybookReadService::class)->findPresentation('pb-weekly-gads');
        Http::assertNothingSent();
    }

    public function test_unauthorized_manage_denied(): void
    {
        $stranger = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(PlaybookService::class)->create($this->anyPayload(['title' => 'Nope']), $stranger);
    }

    public function test_task_done_does_not_attach_playbook(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Independent task',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->actor, 'pb:task:1');

        $this->assertFalse(Schema::hasColumn('tasks', 'playbook_id'));
        $this->assertFalse(Schema::hasColumn('tasks', 'playbook_revision_id'));
        $task->forceFill(['status' => 'completed'])->save();
        $this->assertSame(0, Playbook::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function anyPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Generic SOP',
            'summary' => 'Summary',
            'knowledge' => ['purpose' => 'Purpose'],
            'cadence' => 'weekly',
            'service_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
            'service_definition_ids' => [],
            'asset_types' => [],
            'execution_scopes' => [],
            'instructions' => [['body' => 'Do the work']],
            'references' => [],
        ], $overrides);
    }

    private function createAnyPlaybook(string $title): Playbook
    {
        return app(PlaybookService::class)->create(
            $this->anyPayload(['title' => $title]),
            $this->actor,
            'create:'.md5($title.microtime(true)),
        );
    }
}
