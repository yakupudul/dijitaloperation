<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Today\TodayPage;
use App\Models\Brand;
use App\Models\ClaudeNote;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Ads\SupersededAdAccounts;
use App\Services\Operator\BrandOutcome;
use App\Services\Operator\BrandShortcuts;
use App\Services\Operator\BrandToday;
use App\Services\Operator\BrandWorkplace;
use App\Support\AssetIntent;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Arayüz kararı (yakup 2026-11-17): the brand page is the one workplace, Bugün sorts the brands by who needs the
 * operator, a known intent silences an asset, the Denetçi's remarks are answered in place.
 */
final class InterfaceDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_outcome_says_unmeasured_instead_of_zero(): void
    {
        $outcome = app(BrandOutcome::class)->for($this->brand);

        $this->assertFalse($outcome['has_sources']);
        $this->assertSame(['call', 'whatsapp', 'form'], array_slice(array_column($outcome['kinds'], 'key'), 0, 3));
        $this->assertSame([false, false, false], array_slice(array_column($outcome['kinds'], 'measured'), 0, 3));
        $this->assertNull($outcome['cost_per']);
        $this->assertLessThanOrEqual($outcome['to'], $outcome['from']);
    }

    public function test_brand_page_opens_with_result_assets_gaps_and_the_operators_own_jobs(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'Atlas', 'domain' => 'atlas.com', 'primary_url' => 'https://atlas.com/']);
        foreach (['Sözleşmeyi gönder', 'Logoyu iste', 'Fatura kes', 'Dördüncü iş'] as $title) {
            Reminder::query()->create(['user_id' => $this->admin->id, 'brand_id' => $this->brand->id, 'kind' => 'task', 'title' => $title, 'repeat' => 'none']);
        }

        $workplace = app(BrandWorkplace::class);
        $assets = $workplace->assets($this->brand);
        $this->assertSame([$site->id], array_column($assets, 'id'));
        $missing = $workplace->missing($this->brand, $assets, app(BrandOutcome::class)->for($this->brand));
        $this->assertContains('İşletme profili bağlı değil', array_column($missing, 'text'));
        $this->assertContains('claude', array_column($missing, 'who'), 'measurement is Claude\'s job, never the operator\'s');
        $yours = $workplace->yours($this->brand);
        $this->assertCount(BrandWorkplace::YOURS_LIMIT, $yours['items']);
        $this->assertSame(1, $yours['more']);

        $page = Livewire::test(BrandShow::class, ['brand' => $this->brand->id])
            ->assertSee('Bu ay müşteri teması')->assertSee('Dijital varlıklar')->assertSee('Eksik bağlantılar')->assertSee('Claude yaptı')->assertSee('Sana kalan')
            ->assertSee('Kısayollar')->assertSeeHtml('data-brand-outcome');
        $first = $yours['items'][0]['id'];
        $page->call('finishTodo', $first);
        $this->assertNotNull(Reminder::query()->find($first)->done_at);
    }

    public function test_known_intent_silences_an_asset(): void
    {
        $old = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'module_id' => null, 'name' => 'Eski Ads']);
        $this->assertFalse(AssetIntent::quiet($old->id));

        Livewire::test(BrandShow::class, ['brand' => $this->brand->id])->call('setAssetIntent', $old->id, DigitalAsset::INTENT_RETIRED, 'yeni hesaba geçildi');
        \Illuminate\Support\Once::flush();

        $old->refresh();
        $this->assertSame(DigitalAsset::INTENT_RETIRED, $old->intent);
        $this->assertTrue(AssetIntent::quiet($old->id));
        $this->assertTrue(SupersededAdAccounts::isSuperseded($old->id));
        $row = collect(app(BrandWorkplace::class)->assets($this->brand))->firstWhere('id', $old->id);
        $this->assertSame('muted', $row['tone']);
        $this->assertStringContainsString('Eski hesap', $row['line']);
        $this->assertStringContainsString('yeni hesaba geçildi', $row['line']);

        Livewire::test(BrandShow::class, ['brand' => $this->brand->id])->call('setAssetIntent', $old->id, '');
        \Illuminate\Support\Once::flush();
        $this->assertFalse(AssetIntent::quiet($old->id));
    }

    public function test_shortcuts_grey_what_is_not_connected_and_offer_copy_items(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'Atlas', 'domain' => 'atlas.com', 'primary_url' => 'https://atlas.com/']);
        $profile = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'module_id' => null, 'name' => 'Atlas Bornova']);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'display_name' => 'Atlas Bornova', 'metadata' => ['place_id' => 'ChIJabc']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $profile->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile']);

        $groups = collect(app(BrandShortcuts::class)->for($this->brand))->keyBy('group');
        $this->assertSame(['Web sitesi', 'İşletme profili', 'Reklam', 'Ölçüm', 'Müşteri', 'Marka'], $groups->keys()->all());
        $this->assertContains(route('operator.website', ['assetId' => $site->id]), array_column($groups['Web sitesi']['items'], 'url'));
        $this->assertContains('Yorumlar ve yanıtlar', array_column($groups['İşletme profili']['items'], 'label'));
        $this->assertSame(['missing', 'missing'], array_column($groups['Reklam']['items'], 'kind'));
        $this->assertSame(['Search Console bağlı değil', 'Google Analytics bağlı değil'], array_column($groups['Ölçüm']['items'], 'label'));
        $this->assertContains('copy', array_column($groups['Web sitesi']['items'], 'kind'));
    }

    public function test_today_sorts_brands_by_system_gaps_and_the_operators_own_jobs(): void
    {
        $calm = Brand::factory()->create(['name' => 'Bora Klinik']);
        foreach ([$this->brand, $calm] as $brand) {
            DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => $brand->name]);
            DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'module_id' => null, 'name' => $brand->name]);
        }
        Reminder::query()->create(['user_id' => $this->admin->id, 'brand_id' => $calm->id, 'kind' => 'task', 'title' => 'Erişim iste', 'repeat' => 'none', 'remind_at' => now()->subDays(3)]);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Markasız iş', 'repeat' => 'none']);

        $data = app(BrandToday::class)->rows();
        $rows = collect($data['rows'])->keyBy('id');
        if ($rows->has($calm->id)) {
            $this->assertSame($calm->id, $data['rows'][0]['id'], 'a late to-do of the operator puts the brand on top');
            $this->assertSame([1, 1], [$rows[$calm->id]['todos'], $rows[$calm->id]['late']]);
            $this->assertSame('bad', $rows[$calm->id]['tone']);
            $this->assertSame(1, $data['loose']);
        }

        $note = ClaudeNote::query()->create(['brand_id' => $this->brand->id, 'kind' => ClaudeNote::AUDIT, 'text' => 'Reklam parasının üçte biri rakip hekim adlarına gidiyor.', 'status' => ClaudeNote::OPEN]);
        $other = ClaudeNote::query()->create(['brand_id' => null, 'kind' => ClaudeNote::AUDIT, 'text' => 'Ekranlar 4 saniyede açılıyor.', 'status' => ClaudeNote::OPEN]);
        Livewire::test(TodayPage::class)->assertSee('Denetçi diyor ki')->assertSee('rakip hekim adlarına')->assertSee('Haklısın')
            ->call('agree', $note->id)->call('disagree', $other->id)->assertDontSee('Denetçi diyor ki');
        $this->assertSame(ClaudeNote::AGREED, $note->refresh()->status);
        $this->assertSame(ClaudeNote::REJECTED, $other->refresh()->status);

        $this->get('/')->assertOk()->assertSee('data-today', false);
        $this->get(route('operator.komuta'))->assertOk();
    }
}
