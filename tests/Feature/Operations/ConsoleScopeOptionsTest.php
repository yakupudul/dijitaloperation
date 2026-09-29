<?php

namespace Tests\Feature\Operations;

use App\Enums\CustomerStatus;
use App\Jobs\RunChannelAnalystJob;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Analyst\AnalystRegistry;
use App\Support\Console\ConsoleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Production: `moxdop:analyst:weekly --brand=Panorama` → "No query results for model [App\Models\Brand] 0" ((int)
 * "Panorama" is 0). Every moxdop:* --brand / --asset / --site / --website option takes an id OR a partial,
 * case-insensitive name like moxdop:diagnose, and several matches stop with the list of candidates.
 */
final class ConsoleScopeOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $panorama;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->panorama = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->panorama->id, 'type' => 'website', 'status' => 'active',
            'name' => 'Panorama web', 'primary_url' => 'https://panoramaankara.test/', 'domain' => 'panoramaankara.test']);
    }

    public function test_analyst_weekly_accepts_a_brand_name(): void
    {
        Bus::fake([RunChannelAnalystJob::class]);

        $this->artisan('moxdop:analyst:weekly', ['--brand' => 'panorama'])
            ->expectsOutputToContain('Marka #'.$this->panorama->id.' Panorama Ankara')->assertExitCode(0);

        $this->assertSame(count(app(AnalystRegistry::class)->liveChannels()), AnalystRun::query()->where('brand_id', $this->panorama->id)->count());
    }

    public function test_ambiguous_and_unknown_brands_stop_with_the_candidates(): void
    {
        $izmir = Brand::factory()->create(['customer_id' => $this->panorama->customer_id, 'name' => 'Panorama İzmir']);

        $this->artisan('moxdop:analyst:weekly', ['--brand' => 'Panorama'])
            ->expectsOutputToContain('#'.$this->panorama->id.' Panorama Ankara, #'.$izmir->id.' Panorama İzmir')
            ->assertExitCode(2);
        $this->artisan('moxdop:analyst:weekly', ['--brand' => 'Yok Böyle'])->expectsOutputToContain('Marka bulunamadı')->assertExitCode(2);
        $this->artisan('moxdop:analyst:weekly', ['--brand' => '999999'])->expectsOutputToContain('Marka bulunamadı: #999999')->assertExitCode(2);
        $this->assertSame(0, AnalystRun::query()->count());

        // An exact name among partial matches wins.
        $this->assertSame($this->panorama->id, ConsoleScope::brand('panorama ankara')->id);
    }
}
