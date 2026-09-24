<?php

namespace Tests\Feature\Reports;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Reports\ChartAnnotationsPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use App\Services\MonthlyReport\ChartAnnotations;
use App\Services\MonthlyReport\MonthlyReportBuilder;
use App\Services\MonthlyReport\ReportChart;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 10a: chart notes — global algorithm / regulation notes and brand notes on report charts.
 */
final class ChartAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_are_added_scoped_and_marked_on_charts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $other = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);

        Livewire::test(ChartAnnotationsPage::class)
            ->set('form.kind', 'algorithm')->set('form.starts_on', '2026-09-12')->set('form.ends_on', '2026-09-20')->set('form.title', 'Eylül core update')->call('save')->assertSee('Not eklendi')
            ->set('form.kind', 'campaign')->set('form.starts_on', '2026-09-03')->set('form.title', 'İmplant kampanyası')->call('save')->assertHasErrors('brand_id')
            ->set('form.brand_id', (string) $brand->id)->call('save')->assertHasNoErrors()
            ->set('form.brand_id', (string) $other->id)->set('form.kind', 'site_change')->set('form.starts_on', '2026-09-05')->set('form.title', 'Başka marka')->call('save');

        $rows = app(ChartAnnotations::class)->between($brand->id, '2026-09-01', '2026-09-30');
        $this->assertSame(['İmplant kampanyası', 'Eylül core update'], array_column($rows, 'title'), 'global + own brand, not another brand');
        $this->assertSame([3 => 'İmplant kampanyası', 12 => 'Eylül core update'], ChartAnnotations::markers($rows, '2026-09'));
        $this->assertSame([], app(ChartAnnotations::class)->between($brand->id, '2026-10-01', '2026-10-31'));
        $this->assertCount(1, app(ChartAnnotations::class)->between($brand->id, '2026-09-15', '2026-09-30'), 'a range note still running counts');

        $payload = app(MonthlyReportBuilder::class)->build($brand, '2026-09');
        $this->assertSame(['İmplant kampanyası', 'Eylül core update'], array_column($payload['annotations'], 'title'));
        $svg = ReportChart::line([1 => 1, 2 => 2], [], 'x', 640, 170, [12 => 'Eylül core update']);
        $this->assertStringContainsString('stroke="#d97706"', $svg);
        $this->assertStringContainsString('Eylül core update', $svg);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        $id = (int) DB::table('chart_annotations')->where('title', 'Eylül core update')->value('id');
        Livewire::test(ChartAnnotationsPage::class)->call('delete', $id)->assertForbidden();
        $this->actingAs($admin);
        Livewire::test(ChartAnnotationsPage::class)->call('delete', $id)->assertSee('Not silindi');
        $this->get(route('operator.reports.annotations'))->assertOk();
    }
}
