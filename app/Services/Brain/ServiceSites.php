<?php

namespace App\Services\Brain;

use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Which operational brands offer a global service and their websites' Search Console query × page rows — the
 * portfolio evidence the Brain learns from. GSC rows are read once per site per request (stored data, no API).
 */
final class ServiceSites
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $gsc = [];

    public function __construct(private readonly SeoPlanInputCollector $collector) {}

    /**
     * Operational website assets of brands that offer the service, keyed by id.
     *
     * @return Collection<int, DigitalAsset>
     */
    public function websites(int $serviceId): Collection
    {
        $brandIds = DB::table('brand_offerings')->where('service_catalog_item_id', $serviceId)->where('status', 'active')->pluck('brand_id')->unique();

        return DigitalAsset::query()->operational()->where('type', 'website')->whereIn('brand_id', $brandIds)->with('brand')->get()->keyBy('id');
    }

    /** Brand offering id of the service for a brand (null when the brand does not offer it). */
    public function offeringId(int $brandId, int $serviceId): ?int
    {
        $id = DB::table('brand_offerings')->where('brand_id', $brandId)->where('service_catalog_item_id', $serviceId)->where('status', 'active')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Query × page rows of the site's last $days days (query, page, url_key, clicks, impressions, position).
     *
     * @return list<array<string, mixed>>
     */
    public function gscRows(DigitalAsset $site, int $days = 90): array
    {
        if (isset($this->gsc[$site->id])) {
            return $this->gsc[$site->id];
        }
        try {
            $end = CarbonImmutable::now()->subDays(3);
            $rows = $this->collector->gsc($site, $end->subDays($days - 1), $end)['rows'];
        } catch (Throwable $exception) {
            report($exception);
            $rows = [];
        }

        return $this->gsc[$site->id] = $rows;
    }
}
