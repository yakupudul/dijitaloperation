<?php

namespace Tests\Feature\Brand;

use App\Enums\GoalKind;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\Suggestion;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** yakup 2026-11-06: Moximu's own brand card follows the clinic-only moximu.com. */
class MoximuBrandRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_agency_services_are_archived_and_clinic_services_set(): void
    {
        $sector = ServiceCategory::query()->firstOrCreate(['code' => 'digital'], ['name' => 'Dijital Hizmetler', 'normalized_key' => 'dijital hizmetler']);
        $brand = Brand::factory()->create(['name' => 'Moximu', 'sector' => 'Dijital Hizmetler', 'sector_id' => $sector->id]);
        $old = app(BrandOfferingService::class)->resolveOrCreate($brand, 'Ticimax Kurulum ve Yönetim')['offering'];
        // A ★ set on the services screen has no rank (as live): it must not stay main.
        app(BrandOfferingService::class)->resolveOrCreate($brand, 'Google Ads Danışmanlığı')['offering']->forceFill(['priority' => 'main'])->save();
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

        // Part 3: archived services lose the ★, new ones get search phrases, their unsent content ideas are dismissed.
        $old->forceFill(['priority' => 'main'])->save();
        $cluster = Cluster::query()->create(['sector_id' => $brand->sector_id, 'service_id' => $old->service_catalog_item_id, 'name' => 'Ticimax nedir', 'intent' => 'info', 'page_type' => 'article', 'approved' => true]);
        $idea = fn (string $title, array $action): Suggestion => Suggestion::query()->create([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title), 'material_hash' => 'x', 'title' => $title,
            'reason' => 'r', 'priority' => 2, 'action_type' => SiteSuggestionTypes::CONTENT, 'status' => Suggestion::APPROVED, 'cluster_id' => $cluster->id,
            'action' => ['site_id' => $site->id, 'kind' => 'new'] + $action, 'evidence' => []]);
        $planned = $idea('Ticimax nedir', []);
        $sent = $idea('Ticimax kurulumu', ['article_write_id' => 5]);

        $part3 = require database_path('migrations/2027_01_07_142000_moximu_content_follows_clinic_services.php');
        $part3->apply($brand);

        $this->assertFalse($old->fresh()->isMain());
        $this->assertSame(Suggestion::DISMISSED, $planned->fresh()->status);
        $this->assertSame(Suggestion::APPROVED, $sent->fresh()->status);
        $this->assertTrue(ServiceMatchingKeyword::query()->where('service_catalog_item_id', $seo->service_catalog_item_id)->where('label', 'klinik seo')->exists());
    }

    public function test_an_archived_service_is_never_main(): void
    {
        $brand = Brand::factory()->create();
        $offering = app(BrandOfferingService::class)->resolveOrCreate($brand, 'Web Site Tasarım')['offering'];
        $offering->forceFill(['priority' => 'main'])->save();
        $this->assertFalse(app(BrandOfferingService::class)->archive($offering)->isMain());
    }
}
