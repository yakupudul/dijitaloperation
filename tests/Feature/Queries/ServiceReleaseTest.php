<?php

namespace Tests\Feature\Queries;

use App\Models\Cluster;
use App\Models\Query;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\QueryPipeline;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Site\SiteTestCase;

/** Queries of an archived or deleted service go back to triage; its emptied unlocked clusters go. */
final class ServiceReleaseTest extends SiteTestCase
{
    public function test_archiving_a_service_releases_its_queries_and_clusters(): void
    {
        $cluster = $this->cluster($this->zirkonyum, 'Zirkonyum', ['zirkonyum kaplama fiyatları', 'zirkonyum diş']);
        $kept = $this->cluster($this->implant, 'İmplant', ['implant fiyatları']);
        Query::query()->update(['ai_checked_at' => now(), 'locked' => true, 'assignment' => 'ai']);

        app(ServiceCatalogService::class)->setStatus($this->zirkonyum, 'archived');

        $released = Query::query()->whereIn('text', ['zirkonyum kaplama fiyatları', 'zirkonyum diş'])->get();
        $this->assertSame([[null, null, 'none', false]], $released->map(fn (Query $q): array => [$q->service_id, $q->ai_checked_at, $q->assignment, (bool) $q->locked])->unique()->values()->all());
        $this->assertNull($cluster->fresh(), 'the emptied unlocked cluster goes');
        $this->assertSame($this->implant->id, (int) Query::query()->where('text', 'implant fiyatları')->value('service_id'), 'other services untouched');
        $this->assertNotNull($kept->fresh());
    }

    public function test_deleting_a_service_releases_its_queries_and_a_sweep_catches_older_orphans(): void
    {
        $this->cluster($this->implant, 'İmplant', ['implant fiyatları']);
        app(ServiceCatalogService::class)->delete($this->implant);
        $this->assertNull(Query::query()->where('text', 'implant fiyatları')->value('service_id'));

        // An orphan left by an older archive (written straight to the table) is released by the sweep.
        $this->cluster($this->zirkonyum, 'Zirkonyum', ['zirkonyum diş']);
        DB::table('service_catalog_items')->where('id', $this->zirkonyum->id)->update(['status' => 'archived']);

        $this->assertSame(1, QueryPipeline::releaseServices());
        $this->assertNull(Query::query()->where('text', 'zirkonyum diş')->value('service_id'));
        $this->assertSame(0, Cluster::query()->count());
    }
}
