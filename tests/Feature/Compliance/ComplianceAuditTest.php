<?php

namespace Tests\Feature\Compliance;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Livewire\Operator\Compliance\CompliancePage;
use App\Livewire\Operator\Settings\SectorPacksPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\ComplianceFinding;
use App\Models\ComplianceRule;
use App\Models\Customer;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ComplianceAuditTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $meta;

    private AdvisorItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        $this->meta = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Atlas Meta']);
        $plan = AdvisorPlan::query()->create(['channel' => 'meta_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->meta->id, 'status' => 'completed', 'completed_at' => now()]);
        $this->item = AdvisorItem::query()->create([
            'channel' => 'meta_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->meta->id,
            'item_key' => 'k1', 'category' => 'ads', 'rule_id' => 'creative-fatigue', 'severity' => 'medium', 'priority_score' => 300,
            'title' => 'Reklam yoruldu', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
            'draft_status' => 'ready', 'draft' => ['primary_texts' => ['Garantili implant tedavisi Kadıköy’de.'], 'headlines' => ['İmplant hakkında bilgi alın']],
        ]);
    }

    public function test_checker_matches_folded_suffixed_and_symbol_phrases(): void
    {
        $rules = app(SectorPackRegistry::class)->rulesForBrand($this->brand);
        $checker = app(ComplianceChecker::class);

        $hits = collect($checker->checkText('Türkiye’nin EN İYİ kliniği! %100 memnuniyet, indirimli fiyatlar.', $rules, 'website'))->pluck('rule.rule_key')->all();
        $this->assertEqualsCanonicalizing(['superlatives', 'guarantees', 'inducements'], $hits);
        $this->assertSame([], $checker->checkText('İmplant tedavisi süreci hakkında bilgilendirme.', $rules, 'website'));
        $this->assertSame('minors_targeting', $checker->checkTargeting(['age_min' => 16, 'age_max' => 65], $rules)[0]['rule']->rule_key);
        $this->assertSame([], $checker->checkTargeting(['age_min' => 21], $rules));
        $this->assertFalse(ComplianceRule::query()->where('rule_key', 'institution_identity')->value('active'), 'required-phrase rule is off until legal review');
    }

    public function test_scan_covers_all_sources_resolves_fixed_content_and_keeps_dismissals(): void
    {
        DB::table('meta_creative_snapshot')->insert($this->pool(['account_id' => 'act_1', 'creative_id' => 'c1', 'metadata' => json_encode(['title' => 'Kampanyalı implant', 'body' => 'Bu ay indirimli'])]));
        DB::table('meta_adset_targeting_snapshot')->insert($this->pool(['external_resource_id' => 1, 'account_id' => 'act_1', 'adset_id' => 's1', 'adset_name' => 'Genç kitle', 'targeting' => json_encode(['age_min' => 16])]));
        DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => $this->meta->id, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1', 'title' => 'Atlas Dental',
            'profile' => json_encode(['description' => 'Ağrısız implant tedavisi.']), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $stats = app(ComplianceAuditor::class)->scan($this->brand);

        $sources = ComplianceFinding::query()->where('status', 'open')->get()->map(fn ($f) => $f->source.':'.$f->rule->rule_key)->sort()->values()->all();
        $this->assertSame(['ai_draft:guarantees', 'gbp:guarantees', 'meta_ad:inducements', 'meta_targeting:minors_targeting'], $sources);
        $this->assertSame(4, $stats['new']);
        $this->assertStringContainsString('Garantili implant', ComplianceFinding::query()->where('source', 'ai_draft')->value('excerpt'));

        $this->assertSame(0, app(ComplianceAuditor::class)->scan($this->brand)['new'], 'same findings are updated, not duplicated');

        $gbp = ComplianceFinding::query()->where('source', 'gbp')->sole();
        Livewire::test(CompliancePage::class)->assertSee('Ağrısız implant')->call('dismiss', $gbp->id, 'Hukuk onayladı');
        $this->item->forceFill(['draft' => ['primary_texts' => ['İmplant tedavisi hakkında bilgi alın.']]])->save();
        app(ComplianceAuditor::class)->scan($this->brand);

        $this->assertSame('resolved', ComplianceFinding::query()->where('source', 'ai_draft')->value('status'));
        $this->assertSame('dismissed', $gbp->fresh()->status);
        $this->assertSame('Hukuk onayladı', $gbp->fresh()->note);
    }

    public function test_disabled_pack_or_other_sector_makes_no_findings(): void
    {
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'sector' => null]);
        $this->assertSame(0, app(ComplianceAuditor::class)->scan($other)['rules']);

        app(SectorPackRegistry::class)->setEnabled('health', false, null);
        $this->assertSame(0, app(ComplianceAuditor::class)->scan($this->brand)['rules']);
        $this->assertSame(0, ComplianceFinding::query()->where('status', 'open')->count());
    }

    public function test_ai_draft_shows_a_compliance_badge(): void
    {
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->meta->id])
            ->set('expandedId', $this->item->id)
            ->assertSee('Uyum: 1 sorun')
            ->assertSee('Sonuç garantisi');
    }

    public function test_rules_are_edited_on_screen_and_edits_survive_sync(): void
    {
        $rule = ComplianceRule::query()->firstOrNew(['pack_id' => 'health', 'rule_key' => 'inducements']);
        app(SectorPackRegistry::class)->syncDefaults();
        $rule = ComplianceRule::query()->where('rule_key', 'inducements')->sole();

        Livewire::test(SectorPacksPage::class)
            ->assertSee('Sağlık paketi')
            ->assertSee('hukuk görüşü')
            ->call('edit', $rule->id)
            ->set('editPatterns', "indirim\nkampanya\nçekiliş")
            ->call('save')
            ->set('newPack', 'health')->set('newLabel', 'Mucize tedavi')->set('newPatterns', 'sihirli')->set('newMessage', 'Kullanma')
            ->call('addRule');

        app(SectorPackRegistry::class)->syncDefaults();
        $this->assertSame(['indirim', 'kampanya', 'çekiliş'], $rule->fresh()->patterns);
        $this->assertSame('operator', $rule->fresh()->origin);
        $this->assertTrue(ComplianceRule::query()->where('label', 'Mucize tedavi')->exists());
        $this->get(route('operator.compliance'))->assertOk();
    }

    public function test_stored_website_pages_are_scanned(): void
    {
        Storage::fake('compliance-test');
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/']);
        $html = '<html><head><title>İmplant</title><script>var x = "en iyi";</script></head><body><h1>İmplant</h1><p>Öncesi ve sonrası fotoğraflarımıza bakın.</p></body></html>';
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $site->id, 'provider_or_source' => 'website']);
        Storage::disk('compliance-test')->put('p.html', $html);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(), 'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id,
            'dataset_id' => 'website_html_snapshot', 'batch_key' => 'p', 'provider_or_source' => 'website', 'storage_disk' => 'compliance-test', 'object_key' => 'p.html',
            'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => now()]);
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $site->id, 'url' => 'https://atlasdis.com/implant/', 'raw_ingestion_object_id' => $object->id,
            'html_hash' => hash('sha256', $html), 'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);

        app(ComplianceAuditor::class)->scan($this->brand);

        $finding = ComplianceFinding::query()->where('source', 'website')->sole();
        $this->assertSame('before_after', $finding->rule->rule_key, 'script text is ignored, visible text is checked');
        $this->assertSame('page:https://atlasdis.com/implant/', $finding->subject_ref);
        $this->assertSame('/implant/', $finding->subject_label);
    }

    /** @param  array<string, mixed>  $values */
    private function pool(array $values): array
    {
        return $values + ['digital_asset_id' => $this->meta->id, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)];
    }
}
