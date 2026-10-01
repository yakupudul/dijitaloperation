<?php

namespace Tests\Feature\Brand;

use App\Ai\Agents\BrandSetupAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandGaps;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandSetup\BrandSetupServiceSuggester;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Otomatik kur çok az hizmet çıkardı" and "dosyada 0 hizmet sayfası, sitede 49": every service page of the site is
 * proposed, the dossier counts service pages the way the website screen does, and the nightly upkeep re-runs the site
 * setup when pages were never categorized or matched.
 */
final class SiteUpkeepTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Burçin Öncül']);
        $this->site = DigitalAsset::query()->create(['brand_id' => $this->brand->id, 'name' => 'burcinoncul.com.tr', 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => 'burcinoncul.com.tr', 'primary_url' => 'https://burcinoncul.com.tr']);
        // Many junk URLs sorted before the service section: they must not push the service pages out of the AI input.
        for ($i = 0; $i < 30; $i++) {
            $this->page('/a-arsiv/'.$i, 'Arşiv '.$i);
        }
        $this->page('/hakkinda', 'Hakkında');
        $this->page('/tedavilerimiz/implant-tedavisi', 'İmplant Tedavisi');
        $this->page('/tedavilerimiz/estetik-dis-hekimligi/ankara-gulus-tasarimi', 'Ankara Gülüş Tasarımı');
        $this->page('/tedavilerimiz/ortodonti/ankara-seffaf-plak-invisalign', 'Şeffaf Plak (Invisalign) - Dr. Dt. Burçin ÖNCÜL | Ankara Diş Hekimi');
    }

    public function test_every_service_page_is_proposed_even_when_the_ai_lists_few(): void
    {
        $prompts = [];
        BrandSetupAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['brand_summary' => 'Diş kliniği.', 'sector_code' => 'saglik', 'business_context' => null,
                'services' => [['name' => 'İmplant Tedavisi', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'matching_phrases' => ['implant'], 'is_core' => true, 'evidence' => 'sayfa']],
                'prompt_version' => BrandSetupAgent::PROMPT_VERSION];
        });

        $result = app(BrandSetupServiceSuggester::class)->suggest($this->brand, 'burcinoncul.com.tr', []);

        $this->assertStringContainsString('ankara-gulus-tasarimi', $prompts[0], 'service pages are sent first');
        $names = array_column($result['services'], 'name');
        $this->assertContains('İmplant Tedavisi', $names);
        $this->assertContains('Gülüş Tasarımı', $names, 'the city is removed from the name');
        $this->assertContains('Şeffaf Plak (Invisalign)', $names, 'the site name after " - " is removed');
        $this->assertNotContains('Hakkında', $names);
        $this->assertSame(3, count($names), 'implant is not proposed twice');
        $extra = collect($result['services'])->firstWhere('name', 'Gülüş Tasarımı');
        $this->assertTrue($extra['selected'], 'ticked: the site has its own page');
        $this->assertStringStartsWith('Sitede hizmet sayfası var:', $extra['evidence']);
    }

    public function test_the_dossier_counts_service_pages_like_the_website_screen_and_the_night_reruns_the_site_setup(): void
    {
        $this->assertSame(3, BrandDossier::servicePageCount($this->site), 'uncategorized pages under /tedavilerimiz/ count');

        Queue::fake();
        $this->artisan('moxdop:brands:dossier')->assertSuccessful();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::SETUP);

        // Same uncategorized count the next night: no second run (no nightly AI loop).
        Queue::fake();
        $this->artisan('moxdop:brands:dossier')->assertSuccessful();
        Queue::assertNotPushed(RunSiteOperationJob::class);

        // Categorized, services exist but no page is matched: one more try (once a week).
        Page::query()->where('website_asset_id', $this->site->id)->update(['category' => 'hizmet']);
        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi');
        $this->assertTrue(BrandDossier::siteNeedsSetup($this->site));
        $this->assertFalse(BrandDossier::siteNeedsSetup($this->site), 'not again the same week');
    }

    public function test_gaps_are_reported_and_fixed_only_on_approval(): void
    {
        Queue::fake();
        $component = Livewire::test(BrandDossierTab::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-gaps')->assertSee('3 hizmet sayfası markanın hizmetlerinde yok')->assertSee('Hizmet bölgesi yok');
        $this->assertSame(0, BrandOffering::query()->where('brand_id', $this->brand->id)->count(), 'nothing changes before approval');
        Queue::assertNotPushed(RunSiteOperationJob::class);

        $gap = Suggestion::query()->where('decision_key', BrandGaps::DECISION)->where('title', 'like', '3 hizmet sayfası%')->sole();
        $component->call('applyGap', $gap->id)->assertSee('3 hizmet eklendi');
        $names = SiteScope::offerings($this->brand)->map(fn (BrandOffering $o): string => $o->displayName())->all();
        $this->assertEqualsCanonicalizing(['Gülüş Tasarımı', 'Şeffaf Plak (Invisalign)', 'İmplant Tedavisi'], $names, 'the city is removed');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::SETUP);
        $this->assertSame(Suggestion::APPLIED, $gap->fresh()->status);

        app(BrandGaps::class)->sync($this->brand);
        $this->assertFalse(Suggestion::query()->where('decision_key', BrandGaps::DECISION)->actionable()->where('title', 'like', '%hizmet sayfası%')->exists(), 'covered now');
        $this->assertTrue(Suggestion::query()->where('decision_key', BrandGaps::DECISION)->actionable()->where('title', 'like', '%hiçbir sayfayla eşleşmemiş%')->exists(), 'next: match the pages');
    }

    private function page(string $path, string $title): void
    {
        $url = 'https://burcinoncul.com.tr'.$path;
        Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'path' => $path, 'title' => $title, 'category' => null]);
    }
}
