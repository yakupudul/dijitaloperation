<?php

namespace Tests\Feature\Brand;

use App\Models\Query;
use App\Services\Brand\BrandDossier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Site\SiteTestCase;

/**
 * Panel audit 12 (2026-10-21): two clinics in different cities showed the same "most searched clusters" with the
 * sector pool's impressions as if it were their own demand. The brand file now shows the brand's own Search Console
 * demand and the sector pool on separate lines, each cluster once.
 */
final class BrandDossierDemandTest extends SiteTestCase
{
    public function test_own_demand_and_the_sector_pool_are_separate_lines_and_clusters_are_not_repeated(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $cluster = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları', 'implant fiyatı']);
        $this->cluster($this->implant, 'İmplant fiyatları', ['implant ücreti']);
        Query::query()->whereIn('id', DB::table('cluster_queries')->where('cluster_id', $cluster->id)->pluck('query_id'))->update(['impressions' => 174_095]);
        DB::table('query_sources')->where('external_resource_id', $this->gsc->id)->update(['impressions' => 120, 'month' => '2026-09-01']);

        $demand = app(BrandDossier::class)->build($this->brand)['sections']['demand']['markdown'];

        $this->assertStringContainsString('Markanın kendi talebi (kendi Search Console\'u, son 3 ay): 360 gösterim; en çok: İmplant fiyatları (240)', $demand);
        $this->assertStringContainsString('Sektör havuzu (sektördeki bütün markalar)', $demand);
        $this->assertSame(1, substr_count($demand, 'Sektörde en çok aranan onaylı kümeler: İmplant fiyatları ('));
        $this->assertStringNotContainsString('), İmplant fiyatları (', $demand, 'a same-named cluster is listed once');
    }
}
