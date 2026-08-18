<?php

namespace Tests\Feature\QaBlocker;

use App\Enums\TaskScopeKind;
use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\ProfilePage;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CreateDirectTask;
use App\Support\Roles;
use App\Support\Tasks\TaskStatus;
use App\Support\Work\WorkUrl;
use Database\Seeders\RoleAndPermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PilotBlockerLogoutCaptureWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => 'Pilot Customer A']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Pilot Brand A',
        ]);
    }

    public function test_profile_logout_form_is_not_nested_inside_profile_save_form(): void
    {
        $html = $this->get(route('operator.profile'))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $logoutForms = $xpath->query('//form[contains(@action, "/logout")]');

        $this->assertNotFalse($logoutForms);
        $this->assertGreaterThan(0, $logoutForms->length, 'Visible POST /logout form must exist on Profile');

        foreach ($logoutForms as $form) {
            $parent = $form->parentNode;
            while ($parent) {
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'form') {
                    $this->fail('Sign out form must not be nested inside another form');
                }
                $parent = $parent->parentNode;
            }
        }

        Livewire::test(ProfilePage::class)
            ->assertSee(__('operator.auth.logout'))
            ->assertSee(__('operator.actions.save'));
    }

    public function test_canonical_post_logout_invalidates_session(): void
    {
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_global_capture_requires_customer_with_localized_validation(): void
    {
        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'task', null, null, '/')
            ->assertSet('open', true)
            ->assertSet('prefillCustomer', null)
            ->set('title', 'E2E global capture task')
            ->call('save')
            ->assertHasErrors(['prefillCustomer'])
            ->assertSee(__('operator.capture.validation.customer'))
            ->assertSee(__('operator.forms.customer'));

        $this->assertSame(0, Task::query()->count());
    }

    public function test_global_capture_persists_canonical_task_for_selected_customer(): void
    {
        $otherCustomer = Customer::factory()->create(['name' => 'Pilot Customer B']);
        Brand::factory()->create([
            'customer_id' => $otherCustomer->id,
            'name' => 'Foreign Brand',
        ]);

        $component = Livewire::test(CaptureModal::class)
            ->call('openCapture', 'task', null, null, '/')
            ->set('prefillCustomer', (string) $this->customer->id)
            ->set('prefillBrand', (string) $this->brand->id)
            ->set('title', 'E2E global capture task')
            ->call('save');

        $task = Task::query()->where('title', 'E2E global capture task')->first();
        $this->assertNotNull($task);
        $this->assertSame($this->customer->id, $task->customer_id);
        $this->assertSame($this->brand->id, $task->brand_id);
        $this->assertSame(TaskScopeKind::Brand, $task->scope_kind);
        $this->assertSame($this->admin->id, $task->assignee_id);

        $component->assertRedirect(WorkUrl::show(WorkUrl::TYPE_TASK, $task->id));
    }

    public function test_capture_brand_options_are_limited_to_selected_customer(): void
    {
        $foreignBrand = Brand::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Other Customer'])->id,
            'name' => 'Must Not Appear',
        ]);

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'task', null, null, '/')
            ->set('prefillCustomer', (string) $this->customer->id)
            ->assertSee('Pilot Brand A')
            ->assertDontSee('Must Not Appear')
            ->set('prefillBrand', (string) $foreignBrand->id)
            ->assertSet('prefillBrand', null);
    }

    public function test_customer_and_brand_pages_prefill_capture_context(): void
    {
        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'task', null, null, '/customers/'.$this->customer->id)
            ->assertSet('prefillCustomer', (string) $this->customer->id)
            ->assertSet('prefillBrand', null);

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'task', null, null, '/brands/'.$this->brand->id)
            ->assertSet('prefillCustomer', (string) $this->customer->id)
            ->assertSet('prefillBrand', (string) $this->brand->id);
    }

    public function test_work_detail_uses_explicit_type_and_does_not_guess_from_id(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Typed Task Detail',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->admin, 'pilot-work:task:1');

        $request = ClientRequest::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'title' => 'Typed Client Request Detail',
            'owner_user_id' => $this->admin->id,
        ]);

        $this->get(WorkUrl::show(WorkUrl::TYPE_TASK, $task->id))
            ->assertOk()
            ->assertSee('Typed Task Detail')
            ->assertDontSee(__('operator.work.not_found'))
            ->assertSee(__('operator.work.task_actions.start'));

        $this->get(WorkUrl::show(WorkUrl::TYPE_CLIENT_REQUEST, $request->id))
            ->assertOk()
            ->assertSee('Typed Client Request Detail')
            ->assertDontSee('Typed Task Detail');

        $this->get('/work/'.$task->id)->assertNotFound();

        $this->get('/work/'.$task->id.'?type=task')
            ->assertRedirect(WorkUrl::show(WorkUrl::TYPE_TASK, $task->id));
    }

    public function test_task_status_transition_persists_through_work_show(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Lifecycle Task',
            'customer_id' => $this->customer->id,
            'scope_kind' => TaskScopeKind::Customer->value,
        ], $this->admin, 'pilot-work:task:lifecycle');

        $this->assertSame(TaskStatus::OPEN, $task->status);

        Livewire::test(WorkShow::class, [
            'workId' => (string) $task->id,
            'type' => WorkUrl::TYPE_TASK,
        ])
            ->call('startTask')
            ->assertSee(__('operator.work.statuses.in_progress'));

        $this->assertSame(TaskStatus::IN_PROGRESS, $task->fresh()->status);

        Livewire::test(WorkShow::class, [
            'workId' => (string) $task->id,
            'type' => WorkUrl::TYPE_TASK,
        ])
            ->call('completeTask')
            ->assertSee(__('operator.work.statuses.completed'));

        $this->assertSame(TaskStatus::COMPLETED, $task->fresh()->status);
    }

    public function test_numeric_task_show_redirects_to_typed_work_url(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Redirect Task',
            'customer_id' => $this->customer->id,
            'scope_kind' => TaskScopeKind::Customer->value,
        ], $this->admin, 'pilot-work:task:redirect');

        $this->get(route('operator.task', ['taskId' => $task->id]))
            ->assertRedirect(WorkUrl::show(WorkUrl::TYPE_TASK, $task->id));
    }
}
