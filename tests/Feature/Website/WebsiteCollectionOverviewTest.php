<?php

namespace Tests\Feature\Website;

use App\Enums\Collection\CollectionRunStatus;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebsiteCollectionOverviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_overview_speaks_in_pages_and_hides_dataset_jargon(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'cms' => 'WordPress', 'domain' => 'klinik.example', 'primary_url' => 'https://klinik.example/',
        ]);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'status' => CollectionRunStatus::Running,
            'started_at' => now()->subHour(),
            'request_context' => ['provider_sources' => ['WEBSITE_DIRECT'], 'context' => ['collection_scope' => 'public']],
        ]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'WEBSITE_DIRECT', 'digital_asset_id' => $asset->id,
            'status' => CollectionRunStatus::Running,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resource->id, 'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => 'website_url', 'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            'status' => CollectionRunStatus::Queued, 'started_at' => now()->subHour(), 'rows_written' => 35758,
            'checkpoint' => ['pages' => 330, 'urls_planned' => 1849, 'skipped_unchanged' => 0],
        ]);
        foreach (['hakkimizda', 'iletisim'] as $slug) {
            DB::table('website_cms_object_snapshot')->insert([
                'digital_asset_id' => $asset->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => $slug, 'status' => 'publish',
                'permalink' => 'https://klinik.example/'.$slug.'/', 'observed_at' => now(), 'contract_version' => 1,
                'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $slug),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Livewire::actingAs($admin)->test(WebsiteIntegrationIndex::class, ['assetId' => $asset->id])
            ->assertSee('Sayfalar')
            ->assertSee('330 / 1.849 alındı')
            ->assertSee('tahmini')
            ->assertSee('2 sayfa (son envanter')
            ->assertSee('Son çekim')
            ->assertSee('Nazik mod: aynı anda 2 sayfa · site yavaşlarsa otomatik yavaşlar')
            ->assertDontSee('35.758')
            ->assertDontSee('veri grubu başarılı')
            ->assertDontSee('Hatalı paket')
            ->assertDontSee('Güncellenen');
    }

    #[Test]
    public function a_site_given_a_break_shows_why_and_when_the_crawl_continues(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'domain' => 'klinik.example', 'primary_url' => 'https://klinik.example/',
        ]);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'status' => CollectionRunStatus::Running,
            'started_at' => now()->subHour(),
            'request_context' => ['provider_sources' => ['WEBSITE_DIRECT'], 'context' => ['collection_scope' => 'public']],
        ]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'WEBSITE_DIRECT', 'digital_asset_id' => $asset->id,
            'status' => CollectionRunStatus::Running,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resource->id, 'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => 'website_url', 'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            'status' => CollectionRunStatus::Retrying, 'started_at' => now()->subHour(),
            'checkpoint' => ['pages' => 12, 'urls_planned' => 80, 'politeness' => [
                'mode' => 'backoff', 'concurrency' => 1, 'reason' => 'database', 'next_attempt_at' => now()->addMinutes(15)->toIso8601String(), 'crawl_delay' => null,
            ]],
        ]);

        Livewire::actingAs($admin)->test(WebsiteIntegrationIndex::class, ['assetId' => $asset->id])
            ->assertSee('Site yavaş yanıt veriyor (veritabanı bağlantı hatası); çekim 15 dk sonra')
            ->assertSee('yavaşça sürecek');
    }

    #[Test]
    public function a_finished_crawl_that_read_fewer_pages_than_planned_says_it_stopped_early(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'domain' => 'klinik.example', 'primary_url' => 'https://klinik.example/',
        ]);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'status' => CollectionRunStatus::Completed,
            'started_at' => now()->subHours(5), 'finished_at' => now()->subHour(),
            'request_context' => ['provider_sources' => ['WEBSITE_DIRECT'], 'context' => ['collection_scope' => 'public']],
        ]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'WEBSITE_DIRECT', 'digital_asset_id' => $asset->id,
            'status' => CollectionRunStatus::Completed,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resource->id, 'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => 'website_url', 'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            'status' => CollectionRunStatus::Completed, 'started_at' => now()->subHours(5), 'finished_at' => now()->subHour(),
            'checkpoint' => ['pages' => 826, 'urls_planned' => 4381, 'skipped_unchanged' => 619, 'queue' => []],
        ]);

        Livewire::actingAs($admin)->test(WebsiteIntegrationIndex::class, ['assetId' => $asset->id])
            ->assertSee('826 / 4.381 sayfa · yarım kaldı: 3.555 sayfa okunmadı (sonraki Genel çekim okur)');
    }
}
