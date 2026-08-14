<?php

namespace Tests\Feature\ClientRequests;

use App\Enums\ClientRequestChannel;
use App\Enums\ClientRequestScopeState;
use App\Enums\ClientRequestStatus;
use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Exceptions\ClientRequestInvalidTransitionException;
use App\Exceptions\ClientRequestTargetScopeRequiredException;
use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientRequests\ClientRequestScopeResolver;
use App\Services\ClientRequests\CreateClientRequest;
use App\Services\ClientRequests\CreateTaskFromClientRequest;
use App\Services\ClientRequests\UpdateClientRequest;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ClientRequestProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->customer = Customer::factory()->create(['name' => 'Acme Health']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Acme Dental',
        ]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Website',
        ]);

        $this->actor = User::factory()->create();
        $this->actor->assignRole(Roles::ADMIN);
        $this->actingAs($this->actor);
    }

    public function test_canonical_client_request_table_and_no_v2(): void
    {
        $this->assertTrue(Schema::hasTable('client_requests'));
        $this->assertTrue(Schema::hasColumn('tasks', 'client_request_id'));
        $this->assertFalse(class_exists('App\\Models\\ClientRequestV2'));
        $this->assertTrue(class_exists(ClientRequest::class));
    }

    public function test_request_create_does_not_create_intelligence_or_task_or_scope(): void
    {
        $beforeScopes = CustomerServiceScope::query()->count();

        app(CreateClientRequest::class)->create([
            'title' => 'Please update homepage banner',
            'description' => 'Client asked for a new hero.',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'channel' => ClientRequestChannel::Whatsapp->value,
        ], $this->actor);

        $this->assertSame(1, ClientRequest::query()->count());
        $this->assertSame(0, Evidence::query()->count());
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame($beforeScopes, CustomerServiceScope::query()->count());
        Http::assertNothingSent();
    }

    public function test_identical_titles_create_distinct_requests(): void
    {
        $a = app(CreateClientRequest::class)->create([
            'title' => 'Same title',
            'description' => 'Same body',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor, 'key-a');

        $b = app(CreateClientRequest::class)->create([
            'title' => 'Same title',
            'description' => 'Same body',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor, 'key-b');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, ClientRequest::query()->count());
    }

    public function test_double_submit_idempotency_returns_same_request(): void
    {
        $first = app(CreateClientRequest::class)->create([
            'title' => 'Idempotent capture',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor, 'capture-once');

        $second = app(CreateClientRequest::class)->create([
            'title' => 'Idempotent capture DIFFERENT',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor, 'capture-once');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ClientRequest::query()->count());
        $this->assertSame('Idempotent capture', $second->title);
    }

    public function test_edit_title_preserves_identity(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Original',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $updated = app(UpdateClientRequest::class)->update($request, [
            'title' => 'Renamed',
        ], $this->actor);

        $this->assertSame($request->id, $updated->id);
        $this->assertSame('Renamed', $updated->title);
    }

    public function test_cross_customer_brand_rejected(): void
    {
        $other = Customer::factory()->create();
        $foreignBrand = Brand::factory()->create(['customer_id' => $other->id]);

        $this->expectException(ValidationException::class);

        app(CreateClientRequest::class)->create([
            'title' => 'Cross tenant',
            'customer_id' => $this->customer->id,
            'brand_id' => $foreignBrand->id,
        ], $this->actor);
    }

    public function test_cross_brand_digital_asset_rejected(): void
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $foreignAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id]);

        $this->expectException(ValidationException::class);

        app(CreateClientRequest::class)->create([
            'title' => 'Wrong asset',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $foreignAsset->id,
        ], $this->actor);
    }

    public function test_cross_customer_requester_rejected(): void
    {
        $other = Customer::factory()->create();
        $foreignContact = CustomerContact::query()->create([
            'customer_id' => $other->id,
            'name' => 'Foreign',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateClientRequest::class)->create([
            'title' => 'Bad requester',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'customer_contact_id' => $foreignContact->id,
        ], $this->actor);
    }

    public function test_requester_and_creator_remain_distinct(): void
    {
        $contact = CustomerContact::query()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Dr. Client',
            'email' => 'client@example.com',
        ]);

        $request = app(CreateClientRequest::class)->create([
            'title' => 'Recorded WhatsApp ask',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'customer_contact_id' => $contact->id,
            'channel' => ClientRequestChannel::Whatsapp->value,
        ], $this->actor);

        $this->assertSame($contact->id, $request->customer_contact_id);
        $this->assertSame($this->actor->id, $request->created_by_user_id);
        $this->assertInstanceOf(CustomerContact::class, $request->requester);
        $this->assertInstanceOf(User::class, $request->createdBy);
        $this->assertSame('Dr. Client', $request->requester->name);
        $this->assertSame($this->actor->name, $request->createdBy->name);
    }

    public function test_unclassified_scope_is_not_outside_scope(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Unclassified ask',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $this->assertSame(ClientRequestScopeState::Unclassified, $request->intake_scope_state);

        $current = app(ClientRequestScopeResolver::class)->resolve($request);
        $this->assertSame(ClientRequestScopeState::Unclassified, $current->state);
        $this->assertNotSame(ClientRequestScopeState::OutsideCurrentScope, $current->state);
    }

    public function test_explicit_service_with_active_scope_is_in_scope(): void
    {
        $service = ServiceDefinition::query()->where('code', 'website_maintenance')->firstOrFail();
        app(CustomerServiceScopeService::class)->create(
            customer: $this->customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $request = app(CreateClientRequest::class)->create([
            'title' => 'Update doctor title',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => $service->id,
        ], $this->actor);

        $this->assertSame(ClientRequestScopeState::InScope, $request->intake_scope_state);
        $current = app(ClientRequestScopeResolver::class)->resolve($request);
        $this->assertSame(ClientRequestScopeState::InScope, $current->state);
    }

    public function test_explicit_service_without_scope_is_outside_current_scope(): void
    {
        $service = ServiceDefinition::query()->where('code', 'meta_ads')->firstOrFail();

        $request = app(CreateClientRequest::class)->create([
            'title' => 'Daily Instagram posting',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => $service->id,
        ], $this->actor);

        $this->assertSame(ClientRequestScopeState::OutsideCurrentScope, $request->intake_scope_state);
        $this->assertSame(1, ClientRequest::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, CustomerServiceScope::query()->count());
    }

    public function test_service_scope_change_updates_current_not_intake(): void
    {
        $service = ServiceDefinition::query()->where('code', 'seo')->firstOrFail();

        $request = app(CreateClientRequest::class)->create([
            'title' => 'SEO help',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => $service->id,
        ], $this->actor);

        $this->assertSame(ClientRequestScopeState::OutsideCurrentScope, $request->intake_scope_state);

        app(CustomerServiceScopeService::class)->create(
            customer: $this->customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $current = app(ClientRequestScopeResolver::class)->resolve($request->fresh());
        $this->assertSame(ClientRequestScopeState::InScope, $current->state);
        $this->assertSame(ClientRequestScopeState::OutsideCurrentScope, $request->fresh()->intake_scope_state);
        $this->assertSame($request->id, $request->fresh()->id);
    }

    public function test_service_not_inferred_from_request_text(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Please improve our Google Ads campaign',
            'description' => 'We need SEO and Meta Ads too',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $this->assertNull($request->service_definition_id);
        $this->assertSame(ClientRequestScopeState::Unclassified, $request->intake_scope_state);
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Done already',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        app(UpdateClientRequest::class)->changeStatus($request, ClientRequestStatus::Done, $this->actor);

        $this->expectException(ClientRequestInvalidTransitionException::class);
        app(UpdateClientRequest::class)->changeStatus($request->fresh(), ClientRequestStatus::Triaged, $this->actor);
    }

    public function test_declined_requires_no_task(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Decline me',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        app(UpdateClientRequest::class)->changeStatus($request, ClientRequestStatus::Declined, $this->actor);

        $this->assertSame(0, Task::query()->count());
        $this->assertNotNull($request->fresh()->closed_at);
    }

    public function test_explicit_create_task_with_asset(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Banner change',
            'description' => 'Please change homepage banner',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $task = app(CreateTaskFromClientRequest::class)->create(
            $request,
            [],
            $this->actor,
            'cr-task:'.$request->id.':once',
        );

        $this->assertSame($request->id, $task->client_request_id);
        $this->assertSame($request->customer_id, $task->customer_id);
        $this->assertSame($request->brand_id, $task->brand_id);
        $this->assertSame($this->asset->id, $task->digital_asset_id);
        $this->assertSame(ClientRequestStatus::Planned, $request->fresh()->status);
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        Http::assertNothingSent();
    }

    public function test_create_task_without_asset_requires_explicit_target(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'No asset yet',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $beforeAssets = DigitalAsset::query()->count();

        try {
            app(CreateTaskFromClientRequest::class)->create($request, [], $this->actor);
            $this->fail('Expected TARGET_SCOPE_REQUIRED');
        } catch (ClientRequestTargetScopeRequiredException $exception) {
            $this->assertSame(ClientRequestTargetScopeRequiredException::CODE, 'TARGET_SCOPE_REQUIRED');
            $this->assertSame($request->id, $exception->clientRequest->id);
        }

        $this->assertSame(0, Task::query()->count());
        $this->assertSame(1, ClientRequest::query()->count());
        $this->assertSame($beforeAssets, DigitalAsset::query()->count());
    }

    public function test_create_task_does_not_fallback_to_first_asset(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Other']);

        $request = app(CreateClientRequest::class)->create([
            'title' => 'Still no asset',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $this->expectException(ClientRequestTargetScopeRequiredException::class);
        app(CreateTaskFromClientRequest::class)->create($request, [], $this->actor);
    }

    public function test_task_create_idempotency_and_second_explicit_task(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Decompose me',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $first = app(CreateTaskFromClientRequest::class)->create(
            $request,
            [],
            $this->actor,
            'cr-task:'.$request->id.':nonce-1',
        );
        $retry = app(CreateTaskFromClientRequest::class)->create(
            $request,
            [],
            $this->actor,
            'cr-task:'.$request->id.':nonce-1',
        );
        $second = app(CreateTaskFromClientRequest::class)->create(
            $request,
            ['title' => 'Second slice'],
            $this->actor,
            'cr-task:'.$request->id.':nonce-2',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Task::query()->where('client_request_id', $request->id)->count());
    }

    public function test_task_lifecycle_does_not_auto_close_or_decline_request(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Independent lifecycle',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $task = app(CreateTaskFromClientRequest::class)->create($request, [], $this->actor, 'cr-task:life:1');
        $this->assertSame(ClientRequestStatus::Planned, $request->fresh()->status);

        $task->update(['status' => 'in_progress']);
        $this->assertSame(ClientRequestStatus::Planned, $request->fresh()->status);

        $task->update(['status' => 'completed']);
        $this->assertSame(ClientRequestStatus::Planned, $request->fresh()->status);

        $task->update(['status' => 'cancelled']);
        $this->assertSame(ClientRequestStatus::Planned, $request->fresh()->status);

        app(UpdateClientRequest::class)->changeStatus($request->fresh(), ClientRequestStatus::Done, $this->actor);
        $this->assertSame(1, Task::query()->whereKey($task->id)->count());
    }

    public function test_outside_scope_task_creation_allowed_without_mutating_scope(): void
    {
        $service = ServiceDefinition::query()->where('code', 'meta_ads')->firstOrFail();
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Outside scope ask',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => $service->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $this->assertSame(ClientRequestScopeState::OutsideCurrentScope, $request->intake_scope_state);

        $task = app(CreateTaskFromClientRequest::class)->create($request, [], $this->actor, 'cr-task:oos:1');

        $this->assertNotNull($task->id);
        $this->assertSame(0, CustomerServiceScope::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $snapshot = $task->snapshot_json;
        $this->assertSame(
            ClientRequestScopeState::OutsideCurrentScope->value,
            $snapshot['client_request']['scope_state_at_task_creation'] ?? null,
        );
    }

    public function test_activity_recorded_for_create_status_and_task(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Activity trail',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        app(UpdateClientRequest::class)->changeStatus($request, ClientRequestStatus::Triaged, $this->actor);
        app(CreateTaskFromClientRequest::class)->create($request->fresh(), [], $this->actor, 'cr-task:act:1');

        $events = BrandContextActivity::query()
            ->where('brand_id', $this->brand->id)
            ->where('subject_type', ClientRequest::class)
            ->where('subject_id', $request->id)
            ->pluck('event')
            ->all();

        $this->assertContains('CLIENT_REQUEST_CREATED', $events);
        $this->assertContains('CLIENT_REQUEST_STATUS_CHANGED', $events);
        $this->assertContains('CLIENT_REQUEST_TASK_CREATED', $events);

        foreach (BrandContextActivity::query()->where('subject_id', $request->id)->get() as $activity) {
            $payload = $activity->payload ?? [];
            $this->assertArrayNotHasKey('description', $payload);
            $this->assertArrayNotHasKey('body', $payload);
        }
    }

    public function test_read_service_customer_requests_and_empty_demo_customer(): void
    {
        app(CreateClientRequest::class)->create([
            'title' => 'Visible request',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $rows = app(ClientRequestReadService::class)->forCustomerPresentation($this->customer);
        $this->assertCount(1, $rows);
        $this->assertSame('REAL', $rows[0]['source_state']);

        Livewire::test(CustomerDetail::class, ['customerId' => 'atlas-health'])
            ->call('setTab', 'requests')
            ->assertDontSee('Visible request')
            ->assertSee(__('operator.requests.empty'));
    }

    public function test_capture_modal_persists_production_request(): void
    {
        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'client_request', (string) $this->brand->id, (string) $this->customer->id)
            ->set('title', 'New homepage banner')
            ->set('description', 'Please ship it')
            ->set('source', 'email')
            ->call('save');

        $this->assertTrue(
            ClientRequest::query()->where('title', 'New homepage banner')->exists()
        );
        $this->assertSame(0, Task::query()->count());
    }

    public function test_work_index_client_requests_are_db_backed(): void
    {
        Livewire::test(TasksIndex::class)
            ->call('setView', 'client_requests')
            ->assertDontSee("Update doctor's title on homepage");

        app(CreateClientRequest::class)->create([
            'title' => 'Production work request',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => ServiceDefinition::query()->where('code', 'meta_ads')->value('id'),
        ], $this->actor);

        Livewire::test(TasksIndex::class)
            ->call('setView', 'client_requests')
            ->assertSee('Production work request')
            ->assertSee(__('operator.commercial.outside_scope'));
    }

    public function test_no_provider_calls_on_scope_or_task_paths(): void
    {
        Http::fake();

        $service = ServiceDefinition::query()->where('code', 'website_maintenance')->firstOrFail();
        app(CustomerServiceScopeService::class)->create(
            customer: $this->customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $request = app(CreateClientRequest::class)->create([
            'title' => 'Provider boundary',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'service_definition_id' => $service->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        app(ClientRequestScopeResolver::class)->resolve($request);
        app(ClientRequestReadService::class)->forCustomerPresentation($this->customer);
        app(CreateTaskFromClientRequest::class)->create($request, [], $this->actor, 'cr-task:prov:1');

        Http::assertNothingSent();
    }
}
