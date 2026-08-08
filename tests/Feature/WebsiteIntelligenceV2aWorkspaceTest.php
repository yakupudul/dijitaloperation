<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MoxDop\Website\Diagnosis\DocumentHeadCatalog;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use Tests\TestCase;

class WebsiteIntelligenceV2aWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'moximu.com',
            'domain' => 'moximu.com',
            'primary_url' => 'https://www.moximu.com/',
        ]);
    }

    public function test_performance_and_overview_render_bounded_seo_opportunities_without_new_tab(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => 'gsc_query_page_performance',
            'payload' => [
                'response_ok' => true,
                'requested_period' => ['start' => '2026-07-11', 'end' => '2026-08-07'],
                'rows' => [
                    [
                        'query' => 'moximu agency',
                        'page' => 'https://www.moximu.com/hizmetler',
                        'clicks' => 4,
                        'impressions' => 120,
                        'ctr' => 0.033,
                        'position' => 8.4,
                    ],
                    [
                        'query' => 'tiny',
                        'page' => 'https://www.moximu.com/',
                        'clicks' => 0,
                        'impressions' => 2,
                        'ctr' => 0,
                        'position' => 10,
                    ],
                ],
            ],
        ]);

        $data = app(WebsiteWorkspaceData::class)->for($this->website);
        $this->assertSame(1, $data['seo_opportunities']['count']);
        $this->assertSame('moximu agency', $data['seo_opportunities']['opportunities'][0]['query']);
        $this->assertSame('8.4', $data['seo_opportunities']['opportunities'][0]['position_label']);
        $this->assertSame('3.3%', $data['seo_opportunities']['opportunities'][0]['ctr_label']);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('SEO Opportunities')
            ->assertSee('moximu agency')
            ->assertSee('queries close to stronger positions')
            ->assertDontSee('SEO Audit')
            ->assertSee('Performance')
            ->assertSee('Health')
            ->assertDontSee('access_token');
    }

    public function test_health_groups_document_head_findings_without_raw_json(): void
    {
        Finding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => DocumentHeadCatalog::RULE_TITLE_MISSING,
            'category' => 'document-head',
            'severity' => 'high',
            'title' => 'Title missing',
            'summary' => 'The primary HTML document has no <title> element.',
            'status' => 'open',
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => DocumentHeadCatalog::RULE_JSONLD_MALFORMED,
            'category' => 'structured-data',
            'severity' => 'medium',
            'title' => 'Malformed JSON-LD block',
            'summary' => '1 application/ld+json block(s) failed JSON parsing.',
            'status' => 'open',
        ]);

        $data = app(WebsiteWorkspaceData::class)->for($this->website);
        $labels = collect($data['findings']['health_groups'])->pluck('label')->all();
        $this->assertContains('Document Head', $labels);
        $this->assertContains('Structured Data', $labels);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->set('activeRelationManager', '1')
            ->assertOk()
            ->assertSee('Open issues by area')
            ->assertSee('Document Head')
            ->assertSee('Structured Data')
            ->assertSee('Title missing')
            ->assertSee('Malformed JSON-LD block')
            ->assertDontSee('Credentials JSON')
            ->assertDontSee('"document"');
    }
}
