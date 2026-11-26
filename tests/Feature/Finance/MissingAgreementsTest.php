<?php

namespace Tests\Feature\Finance;

use App\Livewire\Operator\Finance\FinancePage;
use App\Models\Agreement;
use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Finance\MissingAgreements;
use App\Services\Operator\BrandToday;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * yakup 2026-11-26: "Burçin hocanın anlaşması yer almıyor ama sen bu konuda beni uyarmıyorsun". A served brand with no
 * running agreement is shown on Finans and Bugün and gets one to-do, which closes itself once an agreement is entered.
 */
class MissingAgreementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_a_served_brand_without_an_agreement_is_flagged_and_gets_one_todo_that_closes_itself(): void
    {
        $paying = Brand::factory()->create(['name' => 'Panorama']);
        $missing = Brand::factory()->create(['name' => 'Dr. Dt. Burçin Öncül']);
        Brand::factory()->create(['name' => 'Moximu']);
        Agreement::query()->create(['brand_id' => $paying->id, 'customer_id' => $paying->customer_id, 'kind' => 'monthly', 'plan' => 'auto', 'fee' => 15000, 'status' => 'active', 'starts_on' => '2026-11-01', 'due_day' => 15]);

        $service = app(MissingAgreements::class);
        $this->assertSame([$missing->id], $service->brands()->pluck('id')->all(), 'only the brand with no agreement; Moximu pays nobody');

        $this->assertSame(['bound' => 0, 'added' => 1, 'closed' => 0], $service->sync());
        $this->assertSame(['bound' => 0, 'added' => 0, 'closed' => 0], $service->sync(), 'never written twice');
        $todo = Reminder::query()->where('brand_id', $missing->id)->sole();
        $this->assertSame('Dr. Dt. Burçin Öncül için anlaşmayı Finans\'a gir', $todo->title);

        $row = collect(app(BrandToday::class)->rows()['rows'])->firstWhere('id', $missing->id);
        $this->assertStringContainsString('anlaşması yok', $row['line']);
        $this->assertSame('bad', $row['tone']);

        Livewire::test(FinancePage::class)->assertSee('1 markanın anlaşması girilmemiş')->assertSee('Dr. Dt. Burçin Öncül')
            ->call('newJob', $missing->id)->assertSet('tab', 'anlasmalar')->assertSet('job.brand_id', (string) $missing->id);

        Agreement::query()->create(['brand_id' => $missing->id, 'customer_id' => $missing->customer_id, 'kind' => 'monthly', 'plan' => 'auto', 'fee' => 10000, 'status' => 'active', 'starts_on' => '2026-10-01', 'due_day' => 15]);
        $this->assertNotNull($todo->refresh()->done_at, 'entering the agreement ticks the to-do at once');
        $this->assertTrue($service->brands()->isEmpty());
    }

    public function test_a_name_only_job_is_bound_to_the_brand_of_that_name(): void
    {
        $brand = Brand::factory()->create(['name' => 'Ali Akbulut']);
        $job = Agreement::query()->create(['brand_id' => null, 'party' => 'ali  akbulut', 'kind' => 'monthly', 'plan' => 'auto', 'fee' => 10000, 'status' => 'active', 'starts_on' => '2026-11-11', 'due_day' => 11]);

        $this->assertTrue(app(MissingAgreements::class)->brands()->isEmpty(), 'a job under the same name already covers the brand');
        $this->assertSame(1, app(MissingAgreements::class)->sync()['bound']);
        $this->assertSame($brand->id, $job->refresh()->brand_id);
    }
}
