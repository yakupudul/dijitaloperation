<?php

namespace Tests\Feature\BrandSetup;

use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandSetupProposal;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandSetup\BrandSetupApplier;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Otomatik kur çalıştı ama kaydederken 500 hatası aldım": approval must never throw, whatever the AI or an older
 * build stored in the proposal. Every step reports on its own; values are cut to the column sizes PostgreSQL enforces.
 */
final class BrandSetupApplyRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Adadent']);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
    }

    public function test_long_ai_business_model_is_cut_to_the_column_size_instead_of_failing_the_save(): void
    {
        $longModel = str_repeat('Randevulu klinik hizmeti ve paket satış ', 6);
        $proposal = $this->proposal(summary: ['business_context' => [
            'business_summary' => str_repeat('Özet ', 1000),
            'business_model' => $longModel,
            'positioning' => ['dizi', 'olmamalı'],
            'target_audiences' => ['Yetişkinler', null, 42, ['name' => 'Aileler'], ['x' => 'y'], str_repeat('u', 400), 'Yetişkinler'],
            'differentiators' => 'liste değil',
        ]]);

        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->call('approve')
            ->assertHasNoErrors()
            ->assertSet('messageTone', 'success');

        $context = BrandIntelligenceContext::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertSame(64, mb_strlen((string) $context->business_model), 'varchar(64) on PostgreSQL');
        $this->assertLessThanOrEqual(2000, mb_strlen((string) $context->business_summary));
        $this->assertNull($context->positioning, 'a non-text value is ignored');
        $this->assertSame(['Yetişkinler', '42', 'Aileler'], array_slice(array_column($context->target_audiences, 'name'), 0, 3));
        $this->assertSame(160, mb_strlen($context->target_audiences[3]['name']));
        $this->assertSame(BrandSetupProposal::STATUS_APPLIED, $proposal->fresh()->status);
    }

    public function test_messy_service_rows_never_throw_and_duplicates_are_applied_once(): void
    {
        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Diş Beyazlatma', actor: $this->admin);
        $proposal = $this->proposal(services: [
            ['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'is_core' => true, 'aliases' => [null, ['x'], 'Diş İmplantı', 'Diş İmplantı'], 'keywords' => ['düz metin', ['impressions' => 5], ['query' => 'implant fiyat', 'impressions' => 'çok']]],
            ['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed'],
            ['name' => 'Gülüş Tasarımı'],                                   // no status / is_new / is_core / sector
            ['name' => null, 'status' => 'proposed'],
            ['name' => ['dizi'], 'status' => 'proposed'],
            'bozuk satır',
            ['name' => str_repeat('Çok uzun hizmet adı ', 30), 'sector_code' => 'olmayan-sektor', 'is_new' => true, 'status' => 'proposed'],
            ['name' => 'Diş Beyazlatma', 'status' => 'already', 'is_new' => false],
        ], summary: ['sector_code' => 'olmayan-sektor']);

        $results = app(BrandSetupApplier::class)->apply($proposal, $this->admin, [], [0, 1, 2, 3, 4, 5, 6, 7, 99, -1]);

        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $this->brand->id)->get()->map(fn ($o) => $o->primaryName?->raw_label)->all();
        $this->assertContains('İmplant Tedavisi', $offerings);
        $this->assertContains('Gülüş Tasarımı', $offerings);
        $this->assertSame(1, collect($offerings)->filter(fn ($l) => $l === 'İmplant Tedavisi')->count(), 'duplicate AI rows apply once');
        $this->assertSame(1, collect($results)->where('key', 'service:İmplant Tedavisi')->count());
        foreach ($offerings as $label) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $label));
        }
        $sector = collect($results)->firstWhere('key', 'sector');
        $this->assertFalse($sector['ok']);
        $this->assertStringContainsString('katalogda yok', $sector['message']);
        $this->assertSame(BrandSetupProposal::STATUS_APPLIED, $proposal->fresh()->status);
        $this->assertSame($results, $proposal->fresh()->apply_result);
    }

    public function test_messy_items_are_reported_per_row_and_a_failing_step_does_not_stop_the_rest(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $gone = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'resource_type' => 'search_console', 'external_id' => 'sc-domain:gone.com']);
        $goneId = $gone->id;
        $gone->delete();
        $proposal = $this->proposal(items: [
            ['key' => 'asset:website', 'kind' => 'asset', 'url' => 'http://', 'status' => 'proposed'],
            ['key' => 'search_console:'.$goneId, 'kind' => 'bind', 'resource_id' => $goneId, 'target' => 'website', 'status' => 'proposed'],
            ['key' => 'ga4:x', 'kind' => 'bind', 'resource_id' => 'abc', 'status' => 'proposed'],
            ['kind' => 'bind', 'status' => 'proposed'],
            null,
        ], services: [['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed']],
            summary: ['business_context' => ['business_summary' => 'Klinik.']]);
        Schema::drop('brand_intelligence_contexts'); // any database refusal inside one step

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->set('selectedItems', ['asset:website' => true, 'search_console:'.$goneId => true, 'ga4:x' => true])
            ->set('selectedServices', [0 => true])
            ->call('approve')
            ->assertHasNoErrors()
            ->assertSet('messageTone', 'error')
            ->assertSee('Kısmen uygulandı');

        $results = collect($proposal->fresh()->apply_result)->keyBy('key');
        $this->assertStringContainsString('adresi geçersiz', $results['asset:website']['message']);
        $this->assertStringContainsString('Hesap artık listede yok', $results['search_console:'.$goneId]['message']);
        $this->assertFalse($results['ga4:x']['ok']);
        $this->assertFalse($results['context']['ok']);
        $this->assertStringContainsString('veritabanı kaydı reddetti', $results['context']['message']);
        $this->assertTrue($results['service:İmplant Tedavisi']['ok']);
        $this->assertSame(0, DigitalAsset::query()->where('type', 'website')->count());
        $page->call('$refresh')->assertOk()->assertSee('Uygulandı');
    }

    public function test_a_proposal_is_applied_once_and_the_page_renders_old_or_broken_rows(): void
    {
        $proposal = $this->proposal(items: [['key' => 'asset:website', 'url' => 'https://adadent.com.tr/']], services: [['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'selected' => true]],
            summary: ['locations' => ['has_areas' => true, 'mentioned' => [['name' => 'Ankara', 'impressions' => 3]]], 'business_context' => ['target_audiences' => [['nested' => ['x']]], 'business_model' => 'Klinik']]);

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->assertOk()->assertSee('İmplant Tedavisi');
        $page->set('selectedItems', ['asset:website' => true])->call('approve')->assertSet('messageTone', 'success');
        $this->assertSame(1, DigitalAsset::query()->where('type', 'website')->where('brand_id', $this->brand->id)->count());

        // Second click / second tab: nothing is applied again.
        $proposal->forceFill(['status' => BrandSetupProposal::STATUS_READY])->save();
        BrandSetupProposal::query()->whereKey($proposal->id)->update(['status' => BrandSetupProposal::STATUS_APPLIED]);
        $again = app(BrandSetupApplier::class)->apply($proposal, $this->admin, ['asset:website'], [0]);
        $this->assertStringContainsString('zaten uygulandı', $again[0]['message']);
        $page->call('approve')->assertSee('zaten uygulandı');
        $this->assertSame(1, BrandOffering::query()->where('brand_id', $this->brand->id)->count());

        Livewire::withQueryParams(['url' => ['dizi']])->test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->assertOk();
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<mixed>  $services
     * @param  array<string, mixed>  $summary
     */
    private function proposal(array $items = [], array $services = [], array $summary = []): BrandSetupProposal
    {
        return BrandSetupProposal::query()->create([
            'brand_id' => $this->brand->id, 'status' => BrandSetupProposal::STATUS_READY, 'website_url' => 'https://adadent.com.tr/',
            'items' => $items, 'services' => $services, 'services_status' => 'ready', 'summary' => $summary, 'created_by' => $this->admin->id,
        ]);
    }
}
