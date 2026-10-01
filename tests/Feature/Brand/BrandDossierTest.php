<?php

namespace Tests\Feature\Brand;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Marka dosyası: one short file per brand, compiled without AI; section hashes tell an agent what changed. */
final class BrandDossierTest extends TestCase
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
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Ada Klinik'])->id, 'name' => 'Adadent']);
    }

    public function test_it_compiles_every_section_and_reports_only_what_changed(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://adadent.test', 'domain' => 'adadent.test']);
        $dossier = app(BrandDossier::class);
        $first = $dossier->build($this->brand);

        $this->assertSame(array_keys(BrandDossier::SECTIONS), array_keys($first['sections']));
        $this->assertStringContainsString('# Adadent', $first['markdown']);
        $this->assertStringContainsString('Müşteri: Ada Klinik', $first['markdown']);
        $this->assertStringContainsString('https://adadent.test', $first['markdown']);
        $this->assertSame(1, BrandMemory::query()->where('brand_id', $this->brand->id)->where('kind', BrandDossier::KIND)->count());

        $seen = array_map(fn (array $s): string => $s['hash'], $first['sections']);
        $this->assertSame([], BrandDossier::changedSince($this->brand, $seen), 'nothing new: nothing to read');

        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin);
        BrandDossier::saveNotes($this->brand, 'Implant hastası artsın', 'Fiyat yazma');
        $second = $dossier->build($this->brand);

        $this->assertSame(['services', 'demand', 'notes'], BrandDossier::changedSince($this->brand, $seen));
        $this->assertStringContainsString('İmplant Tedavisi', $second['sections']['services']['markdown']);
        $this->assertStringContainsString('Fiyat yazma', $second['sections']['notes']['markdown']);
        $this->assertSame(1, BrandMemory::query()->where('brand_id', $this->brand->id)->where('kind', BrandDossier::KIND)->count(), 'one row, rebuilt in place');
        $this->assertSame(array_keys(BrandDossier::SECTIONS), BrandDossier::changedSince(Brand::factory()->create(), []), 'never built: everything is new');
    }

    public function test_the_tab_shows_the_file_saves_notes_and_rebuilds(): void
    {
        Livewire::test(BrandDossierTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Marka dosyası')->assertSeeHtml('data-dossier-section="identity"')->assertSee('Ada Klinik')
            ->set('goals', 'Kadıköy şubesi öne çıksın')->set('constraints', 'Rakip adı geçmesin')
            ->call('saveNotes')->assertHasNoErrors()->assertSee('Notlar kaydedildi')->assertSee('Rakip adı geçmesin')
            ->call('rebuild')->assertSee('Marka dosyası yenilendi');

        $this->assertSame(['goals' => 'Kadıköy şubesi öne çıksın', 'constraints' => 'Rakip adı geçmesin'], BrandDossier::notes($this->brand));
        $this->assertArrayHasKey('dosya', BrandShow::WORKSPACE_TABS);
    }

    public function test_the_nightly_command_builds_operational_brands(): void
    {
        $this->artisan('moxdop:brands:dossier')->assertSuccessful();
        $built = BrandMemory::query()->where('kind', BrandDossier::KIND)->pluck('brand_id')->all();
        $this->assertSame(Brand::query()->operational()->pluck('id')->all(), $built);
    }
}
