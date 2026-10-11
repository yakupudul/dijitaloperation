<?php

namespace Tests\Feature\Assets;

use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataStatus\DataStatus;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dijital varlıklar: the brand × channel matrix is the default view, rows of the list open the asset, the data badge
 * is one short line with its reason, and open work with critical suggestions shares one column.
 */
final class AssetsIndexLayoutTest extends TestCase
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
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Matris Klinik']);
    }

    public function test_matrix_is_the_default_view_and_a_brand_filter_opens_the_list(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Ads Bir']);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Ads İki']);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Meta Tek']);

        $matrix = Livewire::test(AssetsIndex::class)
            ->assertViewHas('view', 'matrix')
            ->assertSeeHtml('data-estate-matrix')
            ->assertSeeHtml('data-matrix-row="'.$this->brand->id.'"')
            ->assertSee('İşletme Profili')
            ->assertSee('GA4 / GSC')
            ->assertDontSeeHtml('data-asset-row=');
        $rows = $matrix->viewData('matrix')['rows'];
        $this->assertSame(2, $rows[0]['cells']['google_ads']['count']);
        $this->assertStringContainsString('type=google_ads', $rows[0]['cells']['google_ads']['url'], 'several accounts open the filtered list');
        $this->assertSame(1, $rows[0]['cells']['meta_ads']['count']);
        $this->assertSame(0, $rows[0]['cells']['website']['count']);

        Livewire::withQueryParams(['brand' => (string) $this->brand->id])->test(AssetsIndex::class)
            ->assertViewHas('view', 'table')
            ->assertSeeHtml('data-asset-row=')
            ->assertDontSeeHtml('ring-brand-200 hover:bg-brand-50');

        Livewire::test(AssetsIndex::class)->call('setQuickView', 'needs_attention')->assertViewHas('view', 'table');
        Livewire::withQueryParams(['view' => 'cards'])->test(AssetsIndex::class)->assertViewHas('view', 'cards');
    }

    public function test_data_badge_is_short_with_its_reason_and_page_local_tone(): void
    {
        $source = fn (string $state, ?string $reason = null, ?int $age = null): array => ['label' => 'Meta Ads', 'state' => $state, 'state_label' => 'x',
            'tone' => 'muted', 'detail' => 'Son 7–30 gündür harcama yok', 'reconnect' => false, 'reason' => $reason, 'age_days' => $age];

        $idle = AssetsIndex::dataBadge(['sources' => ['meta_ads' => $source(DataStatus::PAUSED, DataStatus::REASON_IDLE, 12)]]);
        $this->assertSame('Durgun · 12 gün harcama yok', $idle['label']);
        $this->assertSame('warn', $idle['tone']);
        $this->assertStringContainsString('Son 7–30 gündür harcama yok', $idle['title']);

        $blocked = AssetsIndex::dataBadge(['sources' => ['meta_ads' => $source(DataStatus::ACCOUNT_BLOCKED, 'meta_disabled')]]);
        $this->assertSame(['Engelli', 'loss'], [$blocked['label'], $blocked['tone']]);
        $this->assertSame('warn', AssetsIndex::dataBadge(['sources' => ['meta_ads' => $source(DataStatus::ACCOUNT_BLOCKED, 'meta_grace_period')]])['tone']);
        $this->assertSame(['Pasif', 'muted'], array_values(array_intersect_key(AssetsIndex::dataBadge(['sources' => ['meta_ads' => $source(DataStatus::PAUSED)]]), array_flip(['label', 'tone']))));
        $this->assertSame('Güncel', AssetsIndex::dataBadge(['sources' => ['meta_ads' => $source(DataStatus::FRESH)]])['label']);
        $this->assertSame('muted', AssetsIndex::dataBadge(['operational_status' => 'inactive', 'sources' => ['meta_ads' => $source(DataStatus::NOT_DELIVERING)]])['tone']);
        $this->assertSame('Kaynak yok', AssetsIndex::dataBadge(['sources' => []])['label']);
    }

    public function test_list_rows_are_clickable_and_work_is_one_column(): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Liste Ads']);

        Livewire::withQueryParams(['view' => 'table'])->test(AssetsIndex::class)
            ->assertSeeHtml('data-asset-row="'.$asset->id.'"')
            ->assertSeeHtml('Livewire.navigate(')
            ->assertSeeHtml('data-asset-work')
            ->assertSeeHtml('aria-label="Veri kaynakları: ')
            ->assertDontSeeHtml('<span class="sr-only">İşlem</span>');
    }
}
