<?php

namespace Tests\Feature\Reports;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Reports\ReportQueuePage;
use App\Mail\MonthlyReportMail;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\Operations\AgencyScorecard;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Rapor kuyruğu (bulk prepare / publish / send) and Ajans karnesi (the month's work and measured wins). */
final class ReportQueueAndScorecardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-10 10:00:00');
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop-reports.auto_commentary' => false]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_missing_reports_are_prepared_and_selected_ones_sent_with_errors_per_brand(): void
    {
        Mail::fake();
        $withMail = $this->brand('Atlas', 'atlas@musteri.test');
        $noMail = $this->brand('Beta', null);

        $page = Livewire::actingAs($this->admin)->test(ReportQueuePage::class)->set('month', '2026-09')->call('prepareMissing');
        $this->assertSame(2, MonthlyReport::query()->where('month', '2026-09')->count());

        $page->set('bulkIds', MonthlyReport::query()->pluck('id')->all())->call('sendSelected');

        Mail::assertSent(MonthlyReportMail::class, fn ($mail): bool => $mail->hasTo('atlas@musteri.test'));
        $this->assertNotNull(MonthlyReport::query()->where('brand_id', $withMail->id)->value('emailed_at'));
        $this->assertStringContainsString('e-posta', (string) MonthlyReport::query()->where('brand_id', $noMail->id)->value('send_error'));
        $this->actingAs($this->admin)->get(route('operator.reports.queue', ['month' => '2026-09']))->assertOk()->assertSee('Rapor kuyruğu')->assertSee('Gönderilemedi');
    }

    public function test_scorecard_counts_the_months_work_and_measured_wins(): void
    {
        $brand = $this->brand('Atlas', null);
        $ads = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => 'active']);
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $brand->id, 'customer_id' => $brand->customer_id, 'digital_asset_id' => $ads->id, 'status' => 'completed', 'completed_at' => now()]);
        AdvisorItem::query()->create(['channel' => 'google_ads', 'brand_id' => $brand->id, 'digital_asset_id' => $ads->id, 'item_key' => 'k', 'category' => 'waste', 'rule_id' => 'negative-keywords',
            'severity' => 'high', 'title' => 'Negatif kelimeler', 'status' => 'done', 'resolved_at' => now()->subDays(3), 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
            'outcome' => ['status' => 'measured', 'metric' => 'Listedeki terimlere harcama', 'before' => 1200, 'after' => 300, 'change_pct' => -75, 'days' => 28, 'good_direction' => 'down'], 'measured_at' => now()->subDay()]);

        $data = app(AgencyScorecard::class)->month('2026-10');

        $this->assertSame(1, $data['totals']['advisor']);
        $this->assertSame(-75, $data['wins'][0]['change_pct']);
        $this->actingAs($this->admin)->get(route('operator.reports.scorecard', ['month' => '2026-10']))->assertOk()->assertSee('Ajans karnesi')->assertSee('Negatif kelimeler');
    }

    private function brand(string $name, ?string $email): Brand
    {
        $brand = Brand::factory()->create(['name' => $name, 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active, 'primary_email' => $email])->id]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active']);

        return $brand;
    }
}
