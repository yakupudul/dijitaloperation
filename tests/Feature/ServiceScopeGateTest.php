<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Jobs\Async\WebsiteDiagnosisJob;
use App\Jobs\RunSeoPlanJob;
use App\Livewire\Operator\Work\AlertsPage;
use App\Models\AdvisorPlan;
use App\Models\AgencyLead;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\DigitalAsset;
use App\Models\Invoice;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Models\SeoPlan;
use App\Models\Task;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Ai\Insights\AiInsightService;
use App\Services\Async\AsyncOperationService;
use App\Services\CommandCenter\CommandCenter;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Support\Roles;
use App\Support\ServiceScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Service scope: nothing is shown, collected, analysed or paid for an asset that is not attached to a brand, or for a
 * passive customer. Items come back when the customer is active again; agency-level items and overdue invoices stay.
 */
final class ServiceScopeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_scope_lists_only_assets_with_a_brand_of_an_active_customer(): void
    {
        $active = $this->website(CustomerStatus::Active);
        $passive = $this->website(CustomerStatus::Inactive);
        $brandless = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'status' => 'active']);
        $scope = app(ServiceScope::class);

        $this->assertSame([$active->id], $scope->operationalAssetIds());
        $this->assertSame([$active->brand_id], $scope->operationalBrandIds());
        $this->assertFalse($scope->isAssetOperational($brandless->id));
        $this->assertFalse($scope->isBrandOperational($passive->brand_id));
        $this->assertTrue($scope->serves(null, null), 'agency-level work is always served');
        $this->assertSame([$active->brand_id], Brand::query()->operational()->pluck('id')->all());

        $passive->brand->customer->update(['status' => CustomerStatus::Active]);
        $this->assertTrue($scope->isAssetOperational($passive->id), 'a status switch clears the memoised scope');
    }

    public function test_brandless_website_produces_no_inbox_item_alert_plan_run_or_collection(): void
    {
        Bus::fake();
        $site = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'status' => 'active']);
        $this->alert($site);

        $this->assertSame([], app(CommandCenter::class)->items()->where('asset_id', $site->id)->all());
        $this->actingAsAdmin();
        Livewire::test(AlertsPage::class)->assertDontSee('Site dönüşümleri düştü');

        $this->assertNotServed(fn () => app(SeoPlanRunner::class)->queue($site, null, 'manual'));
        $this->assertSame(0, app(SeoPlanRunner::class)->queueAll(null, trigger: 'scheduled')->count());
        $this->assertSame(0, SeoPlan::query()->count());

        $result = app(AsyncOperationService::class)->queueWebsiteDiagnosis($site);
        $this->assertFalse($result['queued']);
        $this->assertSame(ServiceScope::NOT_SERVED, $result['message']);
        $this->assertSame(0, Run::query()->count());

        $resource = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:brandless.test']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id]);
        $this->assertNull(app(ResourceAutomationService::class)->portfolioGate($automation), 'free query source: pulled from every account (sorgu hattı)');
        $ga4 = CoreExternalResource::factory()->create(['resource_type' => 'ga4']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $ga4->id, 'capability' => 'ga4']);
        $ga4Automation = ResourceAutomation::query()->create(['external_resource_id' => $ga4->id]);
        $this->assertSame('customer_passive', app(ResourceAutomationService::class)->portfolioGate($ga4Automation), 'bound only to a brandless site: not collected');

        Bus::assertNotDispatched(RunSeoPlanJob::class);
        Http::assertNothingSent();
    }

    public function test_passive_customer_items_disappear_and_come_back_on_reactivation(): void
    {
        $site = $this->website(CustomerStatus::Active);
        $alert = $this->alert($site);
        $center = app(CommandCenter::class);
        $this->assertTrue($center->items()->contains('key', 'alert:'.$alert->id));

        $site->brand->customer->update(['status' => CustomerStatus::Inactive]);
        $center->refresh();
        $this->assertFalse($center->items()->contains('key', 'alert:'.$alert->id), 'hidden while the customer is passive');
        $this->assertNotNull($alert->fresh(), 'hidden, not deleted');
        $this->actingAsAdmin();
        Livewire::test(AlertsPage::class)->assertDontSee('Site dönüşümleri düştü');

        $site->brand->customer->update(['status' => CustomerStatus::Active]);
        $center->refresh();
        $this->assertTrue($center->items()->contains('key', 'alert:'.$alert->id), 'back after reactivation');
        Livewire::test(AlertsPage::class)->assertSee('Site dönüşümleri düştü');
    }

    public function test_agency_items_and_overdue_invoices_stay_while_passive_reminders_go(): void
    {
        $passive = Customer::factory()->create(['status' => CustomerStatus::Inactive, 'name' => 'Eski Klinik']);
        $overdue = Invoice::query()->forceCreate(['customer_id' => $passive->id, 'period' => now()->subMonth()->format('Y-m'), 'amount' => 5000, 'status' => 'issued', 'due_on' => now()->subDays(5)->toDateString()]);
        $followup = CustomerInteraction::query()->forceCreate(['customer_id' => $passive->id, 'channel' => 'call', 'summary' => 'Görüşme', 'next_action' => 'Tekrar ara',
            'next_action_at' => now()->subDay(), 'occurred_at' => now()->subDays(2)]);
        Invoice::query()->forceCreate(['customer_id' => $passive->id, 'period' => now()->format('Y-m'), 'amount' => 5000, 'status' => 'draft']);
        $lead = AgencyLead::query()->create(['name' => 'Ali Veli', 'company' => 'Gülüş Kliniği', 'message' => 'Reklam', 'source' => 'meta_lead_ad', 'status' => 'new', 'received_at' => now()]);
        $passiveTask = Task::factory()->create(['customer_id' => $passive->id, 'brand_id' => null, 'digital_asset_id' => null, 'status' => 'open', 'due_date' => now()->subDay()->toDateString()]);

        $keys = app(CommandCenter::class)->items()->pluck('key');

        $this->assertContains('invoice:'.$overdue->id, $keys->all(), 'money owed by a passive customer stays visible');
        $this->assertContains('lead:'.$lead->id, $keys->all(), 'agency-level items stay');
        $this->assertNotContains('followup:'.$followup->id, $keys->all());
        $this->assertNotContains('task:'.$passiveTask->id, $keys->all());
        $this->assertNotContains('invoice:drafts', $keys->all(), 'draft invoices of passive customers raise no reminder');
    }

    public function test_paid_and_ai_calls_are_refused_for_passive_customers(): void
    {
        $ads = $this->asset(CustomerStatus::Inactive, 'google_ads');
        $site = $this->website(CustomerStatus::Inactive);

        $this->assertSame([], app(DataForSeoTaskQueue::class)->post('/v3/serp/google/maps/task_post', 'maps', 'map_grid', $ads->brand_id, [['payload' => ['keyword' => 'implant']]]));
        $this->assertSame(0, DB::table('dataforseo_tasks')->count());

        $this->assertNotServed(fn () => app(AdvisorPlanRunner::class)->queue($ads, null, 'manual'));
        $this->assertSame(0, AdvisorPlan::query()->count());

        $alert = $this->alert($site);
        $this->assertNotServed(fn () => app(AiInsightService::class)->queue('alerts.cause', $alert), 'insight');
        app(AiInsightService::class)->write('alerts.cause', $alert->id);
        $this->assertStringContainsString('Hizmet kapsamı dışında', (string) app(AiInsightService::class)->state('alerts.cause', $alert->id));

        Http::assertNothingSent();
    }

    public function test_queued_work_of_a_just_passivated_customer_exits_without_provider_calls(): void
    {
        Bus::fake();
        $site = $this->website(CustomerStatus::Active);
        $plan = app(SeoPlanRunner::class)->queue($site, null, 'manual');
        $diagnosis = app(AsyncOperationService::class)->queueWebsiteDiagnosis($site)['run'];
        $this->assertNotNull($diagnosis);

        $site->brand->customer->update(['status' => CustomerStatus::Inactive]);
        Bus::fake([]);

        (new RunSeoPlanJob($plan->id))->handle(app(SeoPlanRunner::class));
        $this->assertSame(SeoPlan::STATUS_FAILED, $plan->fresh()->status);
        $this->assertSame(ServiceScope::NOT_SERVED, $plan->fresh()->error_summary);

        app()->call([new WebsiteDiagnosisJob($diagnosis->id), 'handle']);
        $this->assertSame('failed', $diagnosis->fresh()->status);
        $this->assertSame('service_scope', data_get($diagnosis->fresh()->metadata, 'failure_category'));

        Http::assertNothingSent();
    }

    public function test_reactivation_makes_paused_collection_due_from_any_screen(): void
    {
        $site = $this->website(CustomerStatus::Inactive);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:resume.test']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_status' => 'attention',
            'collection_error' => 'customer_passive', 'next_collection_at' => now()->addDays(3)]);

        $site->brand->customer->update(['status' => CustomerStatus::Active]);

        $this->assertNull($automation->fresh()->collection_error);
        $this->assertTrue($automation->fresh()->next_collection_at->lte(now()));
        $this->assertNull(app(ResourceAutomationService::class)->portfolioGate($automation->fresh()));
    }

    private function website(CustomerStatus $status): DigitalAsset
    {
        return $this->asset($status, 'website');
    }

    private function asset(CustomerStatus $status, string $type): DigitalAsset
    {
        $customer = Customer::factory()->create(['status' => $status]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => 'active']);
    }

    private function alert(DigitalAsset $site): AssetAlert
    {
        return AssetAlert::query()->create([
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'alert_key' => hash('sha256', 'ga4_conversions_drop:'.$site->id), 'kind' => 'ga4_conversions_drop',
            'severity' => 'high', 'title' => 'Site dönüşümleri düştü', 'message' => 'Son 7 günde 4 dönüşüm.', 'data' => [],
            'first_detected_at' => now()->subDay(), 'last_detected_at' => now(),
        ]);
    }

    private function assertNotServed(callable $call, string $field = 'asset'): void
    {
        try {
            $call();
            $this->fail('expected the service scope to refuse the call');
        } catch (ValidationException $exception) {
            $this->assertSame(ServiceScope::NOT_SERVED, $exception->errors()[$field][0] ?? null);
        }
    }

    private function actingAsAdmin(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
    }
}
