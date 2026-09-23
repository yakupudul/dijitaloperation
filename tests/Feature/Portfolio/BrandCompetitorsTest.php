<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Operator\Portfolio\BrandCompetitors;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\Portfolio\BrandCompetitorOverview;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BrandCompetitorsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        $this->brand = Brand::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'name' => 'Kendi Klinik',
            'competitors' => "rakip1.com, Adı Olmayan Klinik\nkendi.com",
        ]);
        $website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://kendi.com/', 'domain' => 'kendi.com']);
        BrandIntelligenceContext::query()->create(['brand_id' => $this->brand->id, 'known_competitors' => [['name' => 'Rakip İki', 'url' => 'https://www.rakip2.com/']]]);
        foreach (['rakip3.com', 'kendi.com', 'rakip1.com'] as $domain) {
            DB::table('dataforseo_competitor_domain_snapshot')->insert([
                'digital_asset_id' => $website->id, 'target' => 'kendi.com', 'location_code' => 2792, 'language_code' => 'tr',
                'competitor_domain' => $domain, 'retrieved_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(),
                'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            ]);
        }
    }

    public function test_suggestions_come_from_every_place_competitors_were_written_down(): void
    {
        $overview = app(BrandCompetitorOverview::class)->forBrand($this->brand);

        $suggested = collect($overview['suggestions'])->keyBy('domain');
        $this->assertSame(['rakip1.com', 'rakip2.com', 'rakip3.com'], $suggested->keys()->sort()->values()->all(), 'own domain and duplicates are dropped');
        $this->assertSame('Marka kartı', $suggested['rakip1.com']['source']);
        $this->assertSame('Rakip İki', $suggested['rakip2.com']['name']);
        $this->assertSame('DataForSEO rakip alan adları', $suggested['rakip3.com']['source']);
        $this->assertSame(['Adı Olmayan Klinik'], $overview['name_only']);
    }

    public function test_one_click_add_and_reject_keep_one_list(): void
    {
        $component = Livewire::test(BrandCompetitors::class, ['brandId' => $this->brand->id])
            ->assertSee('rakip2.com')
            ->call('add', 'rakip2.com', 'Rakip İki', 'İşletme bilgileri')
            ->assertSee('Rakip İki rakip listesine eklendi.');

        $competitor = SearchDemandCompetitor::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertSame('approved', $competitor->status);
        $this->assertSame('rakip2.com', $competitor->normalized_domain);
        $this->assertNotContains('rakip2.com', array_column(app(BrandCompetitorOverview::class)->forBrand($this->brand)['suggestions'], 'domain'));

        $component->call('review', $competitor->id, 'rejected');
        $this->assertSame('rejected', $competitor->fresh()->status);
        $this->assertNotContains('rakip2.com', array_column(app(BrandCompetitorOverview::class)->forBrand($this->brand)['suggestions'], 'domain'), 'rejected competitors are not suggested again');

        $component->set('newDomain', 'kendi.com')->call('addManual')->assertHasErrors('newDomain');
    }

    public function test_brand_page_shows_competitors_and_checklist_item(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->call('setTab', 'business')
            ->assertSee('Rakipler')
            ->assertSeeLivewire(BrandCompetitors::class);
    }
}
