<?php

namespace Tests\Feature\Leads;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Portfolio\BrandLeads;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\LeadOutcome;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Services\LeadOutcomes\LeadOutcomeRegistry;
use App\Services\LeadOutcomes\LeadQuality;
use App\Services\MonthlyReport\MonthlyReportBuilder;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lead quality loop (not a CRM): client leads + the outcome the clinic reported, lead quality block, command
 * center reminder per brand, monthly report section.
 */
final class LeadOutcomesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $ads;

    private int $resourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Operatör']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
        $this->resourceId = (int) CoreExternalResource::factory()->create()->id;
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->ads->id, 'external_resource_id' => $this->resourceId, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_meta_utf16_export_is_imported_without_contact_details_and_is_idempotent(): void
    {
        $tsv = implode("\n", [
            "id\tcreated_time\tad_name\tcampaign_name\tfull_name\tphone_number\temail",
            "l:111\t2026-09-20T10:15:00+0300\tReklam A\tİmplant Form\tAyşe Yılmaz\t+90 532 111 4512\tayse@example.test",
            "l:112\t2026-09-21T11:00:00+0300\tReklam A\tİmplant Form\tMehmet Öz\t0533 222 7788\tm@example.test",
            "l:113\t\tReklam A\tİmplant Form\tTarihsiz\t0533 000 0000\t",
        ]);
        $file = "\xFF\xFE".mb_convert_encoding($tsv, 'UTF-16LE', 'UTF-8');

        $stats = app(LeadOutcomeRegistry::class)->import($this->brand, 'meta_lead_form', $file, $this->admin);
        $this->assertSame(['created' => 2, 'existing' => 0, 'skipped' => 1], $stats);
        $lead = LeadOutcome::query()->where('lead_ref', 'l:111')->firstOrFail();
        $this->assertSame('A.Y. ••4512', $lead->contact_hint);
        $this->assertSame('Reklam A', $lead->campaign_label, 'first matching campaign-like column');
        $this->assertSame('new', $lead->status);
        $this->assertSame($this->brand->customer_id, $lead->customer_id);

        $stored = json_encode(DB::table('lead_outcomes')->get());
        foreach (['Ayşe', 'Yılmaz', '532 111', '5321114512', 'ayse@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, (string) $stored, 'no contact details stored');
        }

        $again = app(LeadOutcomeRegistry::class)->import($this->brand, 'meta_lead_form', $file, $this->admin);
        $this->assertSame(['created' => 0, 'existing' => 2, 'skipped' => 1], $again);
    }

    public function test_semicolon_csv_without_id_column_uses_a_keyed_row_reference_and_requires_a_date_column(): void
    {
        $csv = "Tarih;Form;Ad Soyad;Telefon\n20.09.2026 14:30;Web formu;Ali Can;05321234567\n";
        $registry = app(LeadOutcomeRegistry::class);
        $this->assertSame(1, $registry->import($this->brand, 'website_form', $csv, $this->admin)['created']);
        $this->assertSame(1, $registry->import($this->brand, 'website_form', $csv, $this->admin)['existing']);
        $lead = LeadOutcome::query()->firstOrFail();
        $this->assertStringStartsWith('row:', $lead->lead_ref);
        $this->assertStringNotContainsString('05321234567', $lead->lead_ref);
        $this->assertSame('2026-09-20 14:30', $lead->lead_received_at->timezone(config('app.timezone'))->format('Y-m-d H:i'));

        $this->expectException(ValidationException::class);
        $registry->import($this->brand, 'website_form', "Form;Telefon\nx;1\n", $this->admin);
    }

    public function test_quality_counts_outcomes_and_cost_per_qualified_lead_from_ad_spend(): void
    {
        LeadOutcome::factory()->for($this->brand)->count(2)->create(['lead_received_at' => '2026-09-10 10:00:00']);
        LeadOutcome::factory()->for($this->brand)->outcome('appointment')->create(['lead_received_at' => '2026-09-11 10:00:00']);
        LeadOutcome::factory()->for($this->brand)->outcome('sale', 25000)->create(['lead_received_at' => '2026-09-12 10:00:00']);
        LeadOutcome::factory()->for($this->brand)->outcome('junk')->create(['lead_received_at' => '2026-09-12 11:00:00']);
        LeadOutcome::factory()->for($this->brand)->outcome('unreachable')->create(['lead_received_at' => '2026-09-13 11:00:00']);
        LeadOutcome::factory()->for($this->brand)->outcome('sale')->create(['lead_received_at' => '2026-08-01 10:00:00']);
        $this->spend('google_ads_campaign_daily', '2026-09-15', 'cost_amount', 900.0);
        $this->spend('meta_campaign_daily', '2026-09-16', 'spend', 300.0);

        $quality = app(LeadQuality::class)->forBrand($this->brand, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(6, $quality['total']);
        $this->assertSame(4, $quality['marked']);
        $this->assertSame(2, $quality['unmarked']);
        $this->assertSame(2, $quality['qualified']);
        $this->assertSame(50.0, $quality['qualified_rate']);
        $this->assertSame(1200.0, $quality['spend']);
        $this->assertSame(600.0, $quality['cost_per_qualified']);
        $this->assertSame(200.0, $quality['cost_per_lead']);
        $this->assertSame(25000.0, $quality['value']);
    }

    public function test_brand_leads_page_marks_outcomes_saves_value_adds_and_imports(): void
    {
        $lead = LeadOutcome::factory()->for($this->brand)->create(['lead_received_at' => now()->subDays(3), 'campaign_label' => 'İmplant Form']);

        $page = Livewire::test(BrandLeads::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Leadler ve sonuçları')->assertSee('Lead kalitesi')->assertSee('İmplant Form')
            ->call('mark', $lead->id, 'sale')
            ->assertSee('Sonuç kaydedildi: Satış');
        $lead->refresh();
        $this->assertSame(['sale', $this->admin->id], [$lead->status, $lead->marked_by]);
        $this->assertNotNull($lead->marked_at);

        $page->call('edit', $lead->id)->set('values.'.$lead->id, '12.500,50')->set('notes.'.$lead->id, 'İmplant, 2 diş')->call('saveDetails', $lead->id);
        $this->assertSame(['12500.50', 'İmplant, 2 diş', 'sale'], [(string) $lead->fresh()->value_try, $lead->fresh()->note, $lead->fresh()->status]);

        $page->call('edit', $lead->id)->set('values.'.$lead->id, 'abc')->call('saveDetails', $lead->id)->assertHasErrors('values.'.$lead->id);

        $page->set('manual.lead_source', 'phone_call')->set('manual.lead_received_at', '2026-10-04')->set('manual.contact_hint', 'K.T. ••1234')->call('addManual');
        $this->assertSame(1, LeadOutcome::query()->where('lead_source', 'phone_call')->where('contact_hint', 'K.T. ••1234')->count());
        $page->set('manual.lead_received_at', '2027-01-01')->call('addManual')->assertHasErrors('manual.lead_received_at');

        // Livewire drops temporary uploads that look older than a day, so the upload runs on the real clock.
        $this->travelBack();
        $upload = UploadedFile::fake()->createWithContent('leads.csv', "id,created_time,form_name\n900,2026-09-01 09:00,Kampanya B\n");
        $page->set('importSource', 'website_form')->set('importFile', $upload)->call('import')->assertHasNoErrors()->assertSee('1 yeni lead eklendi');
        $this->assertTrue(LeadOutcome::query()->where('lead_source', 'website_form')->where('lead_ref', '900')->exists());

        $other = Brand::factory()->create();
        $foreign = LeadOutcome::factory()->for($other)->create();
        $this->expectException(ModelNotFoundException::class);
        $page->call('mark', $foreign->id, 'sale');
    }

    public function test_brand_page_shows_lead_quality_block_and_route_is_reachable(): void
    {
        LeadOutcome::factory()->for($this->brand)->outcome('appointment')->create(['lead_received_at' => now()->subDays(5)]);
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->call('setTab', 'business')->assertSee('Lead kalitesi')->assertSee('Leadler ve sonuçları');
        $this->get(route('operator.brand.leads', ['brand' => $this->brand->id]))->assertOk()->assertSee('Lead kalitesi');
    }

    public function test_command_center_groups_unmarked_leads_older_than_two_days_per_brand(): void
    {
        LeadOutcome::factory()->for($this->brand)->count(3)->create(['lead_received_at' => now()->subDays(4)]);
        LeadOutcome::factory()->for($this->brand)->create(['lead_received_at' => now()->subDay()]);
        LeadOutcome::factory()->for($this->brand)->outcome('sale')->create(['lead_received_at' => now()->subDays(6)]);
        $other = Brand::factory()->create(['name' => 'Diğer Klinik']);
        LeadOutcome::factory()->for($other)->create(['lead_received_at' => now()->subDays(20)]);

        $items = app(CommandCenter::class)->items()->where('source', 'lead_outcome')->keyBy('brand_id');
        $this->assertCount(2, $items, 'one item per brand');
        $this->assertSame("3 lead'in sonucu girilmedi", $items[$this->brand->id]['title']);
        $this->assertSame('medium', $items[$this->brand->id]['severity']);
        $this->assertSame('high', $items[$other->id]['severity'], 'oldest over 14 days');
        $this->assertStringContainsString('/brands/'.$this->brand->id.'/leads', (string) $items[$this->brand->id]['url']);

        $this->assertSame(1, app(CommandCenter::class)->act(['lead_outcome:'.$this->brand->id], 'snooze', $this->admin, 3));
        $this->assertFalse(app(CommandCenter::class)->items()->contains('key', 'lead_outcome:'.$this->brand->id));
    }

    public function test_monthly_report_has_lead_quality_section_only_when_outcomes_exist(): void
    {
        LeadOutcome::factory()->for($this->brand)->create(['lead_received_at' => '2026-09-10 10:00:00']);
        $this->assertNull(app(MonthlyReportBuilder::class)->build($this->brand, '2026-09')['lead_quality'], 'no outcome marked yet');

        LeadOutcome::factory()->for($this->brand)->outcome('appointment')->create(['lead_received_at' => '2026-09-11 10:00:00']);
        $this->spend('google_ads_campaign_daily', '2026-09-15', 'cost_amount', 500.0);
        $payload = app(MonthlyReportBuilder::class)->build($this->brand, '2026-09');
        $this->assertSame(2, $payload['lead_quality']['total']);
        $this->assertSame(100.0, $payload['lead_quality']['qualified_rate']);
        $this->assertSame(500.0, $payload['lead_quality']['cost_per_qualified']);

        $html = view('reports.monthly.body', ['payload' => $payload, 'commentary' => null, 'note' => null])->render();
        $this->assertStringContainsString('Lead kalitesi', $html);
        $this->assertStringContainsString('Nitelikli lead başı reklam maliyeti', $html);
    }

    private function spend(string $table, string $date, string $column, float $amount): void
    {
        $values = $table === 'google_ads_campaign_daily'
            ? ['customer_id' => '1', 'campaign_id' => 'c1', 'impressions' => 100, 'clicks' => 10, 'cost_micros' => (int) ($amount * 1_000_000), 'cost_amount' => $amount, 'conversions' => 1, 'currency' => 'TRY']
            : ['account_id' => 'act_1', 'campaign_id' => 'm1', 'impressions' => 100, 'clicks' => 10, 'spend' => $amount, 'currency' => 'TRY'];
        DB::table($table)->insert($values + [
            'digital_asset_id' => null, 'external_resource_id' => $this->resourceId, 'reporting_date' => $date,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $table.$date.$column), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
