<?php

namespace Tests\Feature;

use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\BrandFinding;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\Access\AccessLinks;
use App\Services\Brand\Baseline;
use App\Services\Operator\Agenda;
use App\Services\Sales\ClosingSteps;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Satış Düzeni 3.1: "Kazanıldı" is five steps (evet, sözleşme, ilk ödeme, erişimler, kurulum), followed until done. */
class ClosingStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
    }

    public function test_a_won_record_shows_which_step_it_waits_at_until_all_five_are_done(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Ali Akbulut', 'company' => 'Akbulut Diş', 'stage' => 'talking', 'source' => 'whatsapp', 'research_at' => now(),
            'research' => ['business_name' => 'Akbulut Diş', 'person_name' => '', 'kind' => '', 'city' => '', 'district' => '', 'website' => '', 'instagram' => '', 'google_maps' => '', 'maps' => [], 'instagram_facts' => [],
                'site_notes' => [], 'confidence' => 'high', 'sources' => [], 'note' => '', 'findings' => [['title' => 'İşletme Profilinde hizmet listesi boş', 'detail' => 'Profilde tek kategori var, hizmet eklenmemiş.', 'source' => 'Google Maps'], ['title' => 'Sitede implant sayfası yok', 'detail' => '', 'source' => 'site']]]]);
        $closing = app(ClosingSteps::class);
        $this->assertNull($closing->of($lead));

        $brand = app(SalesLeads::class)->win($lead, $this->admin, ['kind' => 'monthly', 'fee' => '14.000', 'starts_on' => now()->toDateString(), 'due_day' => 5]);
        $lead->refresh();
        // Devir (3.3): the sale's findings are the new brand's first findings.
        $handed = BrandFinding::query()->where('brand_id', $brand->id)->orderBy('id')->get();
        $this->assertSame(['İşletme Profili', 'Web sitesi'], $handed->pluck('area')->all());
        $this->assertStringContainsString('Satış analizinden', (string) $handed[0]->evidence);
        $state = $closing->of($lead);
        $this->assertSame('contract', $state['current']);
        $this->assertSame(['yes'], collect($state['steps'])->where('done', true)->pluck('key')->all());
        $this->assertCount(1, $closing->open());
        $this->assertStringStartsWith('Sözleşme: imza', $closing->open()->first()['line']);

        // The card: one action per open step.
        $this->actingAs($this->admin);
        $card = Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->assertSeeHtml('data-lead-closing="contract"')->call('contractSigned');
        $card->assertSeeHtml('data-lead-closing="payment"')->call('firstPaymentTaken')->assertSeeHtml('data-lead-closing="access"');

        // Erişimler: the access link's channels all marked; Kurulum: the brand's first findings.
        $links = app(AccessLinks::class);
        $links->saveIdentities('ajans@example.com', '123456789');
        $link = $links->ask($brand, ['search_console', 'google_business_profile'], $this->admin);
        $links->mark($link, 'search_console');
        $this->assertSame('access', $closing->of($lead)['current']);
        $this->assertStringContainsString('2 erişimden 1', $closing->open()->first()['line']);
        $links->mark($link->refresh(), 'google_business_profile');
        $this->assertSame('setup', $closing->of($lead)['current']);

        // Satış and Komuta keep it in front until the setup is written.
        Livewire::test(SalesPage::class)->assertSeeHtml('data-sales-closing-row="setup"');
        $rows = app(Agenda::class)->today($this->admin->id)['rows'];
        $this->assertSame('closing', $rows[0]['kind']);
        $this->assertSame('Kurulum: başlangıç ölçümü bekliyor', $rows[0]['line']);

        app(Baseline::class)->take($brand);
        $this->assertTrue($closing->of($lead)['finished']);
        $this->assertCount(0, $closing->open());
        Livewire::test(SalesPage::class)->assertDontSeeHtml('data-sales-closing');
    }

    public function test_an_old_win_and_a_win_without_terms_are_handled(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Eski Müşteri', 'stage' => 'talking', 'source' => 'form']);
        app(SalesLeads::class)->win($lead, $this->admin);
        $closing = app(ClosingSteps::class);
        $state = $closing->of($lead->refresh());
        $this->assertSame('Önce anlaşma şartlarını gir', collect($state['steps'])->firstWhere('key', 'contract')['note']);
        $this->assertFalse($closing->contract($lead, now()->toDateString()));

        $lead->forceFill(['won_at' => now()->subDays(ClosingSteps::WINDOW_DAYS + 1)])->save();
        $this->assertCount(0, $closing->open());
    }
}
