<?php

namespace Tests\Feature\Brand;

use App\Livewire\Operator\Portfolio\BrandFindingsCard;
use App\Mcp\Tools\BrandFindings;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\BrandBaseline;
use App\Models\BrandFinding;
use App\Models\BrandReport;
use App\Models\BrandSession;
use App\Models\User;
use App\Services\Brand\MonthlySession;
use App\Services\Moxi\MoxiTools;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Aylık oturum (Marka Düzeni 2.6): one summary, at most five jobs, one approval, then the customer's one page. */
class MonthlySessionTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['name' => 'Özel Demiderm Polikliniği']);
    }

    /** @return array<string, mixed> */
    private function payload(int $jobs = 2): array
    {
        return ['action' => 'session', 'brand_id' => $this->brand->id, 'month' => '2026-11',
            'summary' => 'Organik tık 410 → 520. İki iş tuttu, biri tutmadı.',
            'client_note' => 'Bu ay sitenizin hızına ve iki hizmet sayfasına çalıştık.',
            'report_lines' => [['what' => 'Ana sayfa hızlandırıldı', 'result' => 'Telefonda açılış 6,1 saniyeden 2,9 saniyeye indi.'], ['what' => 'Lazer epilasyon sayfası yenilendi']],
            'jobs' => array_map(fn (int $i): array => ['title' => 'İş '.$i, 'area' => 'Web sitesi', 'evidence' => 'kanıt '.$i, 'plan' => 'plan', 'approval' => 'none'], range(1, $jobs))];
    }

    public function test_a_session_takes_at_most_five_jobs_and_every_job_needs_evidence(): void
    {
        $this->assertStringContainsString('en çok 5', MoxiTools::callMcp(BrandFindings::class, $this->payload(6)));
        $bad = $this->payload(1);
        $bad['jobs'][0]['evidence'] = '';
        $this->assertStringContainsString('kanıtsız', MoxiTools::callMcp(BrandFindings::class, $bad));
        $this->assertSame(0, BrandSession::query()->count());
    }

    public function test_one_approval_hands_the_jobs_to_claude_and_locks_the_session(): void
    {
        MoxiTools::callMcp(BrandFindings::class, $this->payload(3));
        MoxiTools::callMcp(BrandFindings::class, $this->payload(2));
        $session = BrandSession::query()->sole();
        $this->assertSame('draft', $session->status);
        $this->assertCount(2, $session->jobs);
        $this->assertSame(0, BrandFinding::query()->count());

        Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-session="draft"')->assertSee('2 işi onayla')
            ->call('approveSession', $session->id)
            ->assertSeeHtml('data-brand-session="approved"')->assertSee('Müşteri raporunu aç');

        $this->assertSame(2, BrandFinding::query()->where('brand_id', $this->brand->id)->where('status', 'requested')->count());
        $this->assertTrue($session->fresh()->isApproved());
        $this->assertStringContainsString('değiştirilemez', MoxiTools::callMcp(BrandFindings::class, $this->payload(1)));
        $this->assertSame(2, BrandFinding::query()->count());
    }

    public function test_a_brand_in_the_new_order_is_due_thirty_days_after_its_start_numbers(): void
    {
        $this->brand->forceFill(['new_order_at' => now()])->save();
        $baseline = BrandBaseline::query()->create(['brand_id' => $this->brand->id, 'period_from' => now()->subDays(40)->toDateString(), 'period_to' => now()->subDays(10)->toDateString(), 'numbers' => []]);
        $this->assertSame([], MonthlySession::due());
        $baseline->update(['period_to' => now()->subDays(31)->toDateString()]);
        $this->assertSame([$this->brand->id], array_column(MonthlySession::due(), 'brand_id'));
        $payload = $this->payload(1);
        unset($payload['month']);
        MoxiTools::callMcp(BrandFindings::class, $payload);
        $this->assertSame([], MonthlySession::due());
    }

    public function test_the_customer_page_shows_only_the_customers_lines_and_the_tick_marks_the_month(): void
    {
        MoxiTools::callMcp(BrandFindings::class, $this->payload(1));
        $session = BrandSession::query()->sole();
        auth()->logout();
        $this->get('/rapor/'.$session->token)->assertOk()
            ->assertSee('Demiderm')->assertSee('Kasım 2026')->assertSee('Telefonda açılış 6,1 saniyeden 2,9 saniyeye indi.')
            ->assertDontSee('Organik tık 410')->assertDontSee('kanıt 1')->assertDontSee('İş 1');
        $this->get('/rapor/'.str_repeat('a', 48))->assertNotFound();

        $this->actingAs(User::query()->firstOrFail());
        Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])->call('approveSession', $session->id)->call('reportSent', $session->id, true)->assertSee('Müşteriye gönderildi');
        $this->assertNotNull(BrandReport::query()->where('brand_id', $this->brand->id)->where('month', '2026-11')->value('sent_at'));
        $this->get('/rapor/'.$session->token)->assertSee('Önümüzdeki ay')->assertSee('İş 1');
    }
}
