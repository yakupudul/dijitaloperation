<?php

namespace Tests\Feature\Brand;

use App\Enums\GoalKind;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\BrandIntelligence\BrandOfferingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** yakup 2026-11-06: Moximu's own brand card follows the clinic-only moximu.com. */
class MoximuBrandRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_agency_services_are_archived_and_clinic_services_set(): void
    {
        $brand = Brand::factory()->create(['name' => 'Moximu', 'sector' => 'Dijital Hizmetler']);
        $old = app(BrandOfferingService::class)->resolveOrCreate($brand, 'Ticimax Kurulum ve Yönetim')['offering'];
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'moximu.test', 'primary_url' => 'https://moximu.test/']);
        $seoPage = Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://moximu.test/hizmetlerimiz/klinik-seo/', 'url_hash' => hash('sha256', 'seo'), 'path' => '/hizmetlerimiz/klinik-seo/']);

        $migration = require database_path('migrations/2027_01_07_140000_refresh_moximu_brand_clinic_direction.php');
        $migration->apply($brand);
        $migration->apply($brand); // twice: nothing duplicated

        $active = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->get();
        $this->assertCount(7, $active);
        $this->assertSame('archived', $old->fresh()->status->value);
        $main = $active->filter->isMain()->map->displayName()->values()->all();
        $this->assertEqualsCanonicalizing(['Klinik SEO', 'Google İşletme Profili Yönetimi'], $main);

        $seo = $active->first(fn (BrandOffering $o): bool => $o->displayName() === 'Klinik SEO');
        $this->assertTrue(OfferingPage::query()->where('page_id', $seoPage->id)->where('brand_offering_id', $seo->id)->where('locked', true)->exists());

        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        $this->assertStringContainsString('yalnız kliniklerle', $context->business_summary);
        $this->assertSame('Klinik ajansı — aylık abonelik', $context->business_model);
        $this->assertSame(5, BrandGoal::query()->where('brand_id', $brand->id)->where('kind', GoalKind::Business)->where('status', 'active')->count());
        $this->assertSame(4, BrandGoal::query()->where('brand_id', $brand->id)->where('kind', GoalKind::Conversion)->where('status', 'active')->count());
        $this->assertSame(6, BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->count());
    }
}
