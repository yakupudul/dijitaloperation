<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Support\SchemaState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * GA4 conversions as the brand conversion dictionary counts them (panel audit finding 2). GA4's own "keyEvents"
 * column adds up every event the property marks as key, page_view and scroll included (Avrupadent: 19,595 of 21,291
 * were page views), so every screen that shows website conversions reads them from here:
 * - totals per day come from the key event table, limited to the events the dictionary counts;
 * - per page only the inflated column exists (landing page × source), so it is scaled by the counted share of the
 *   same window; a share below RELIABLE_SHARE means the page split says nothing and callers skip it.
 * A brand whose dictionary has no GA4 row yet counts every key event except the known attention events.
 */
final class Ga4Conversions
{
    /** Below this counted share of all key events, per-page conversions are noise. */
    public const float RELIABLE_SHARE = 0.5;

    /** @var array<int, list<string>|null> */
    private array $events = [];

    public function __construct(private readonly BrandConversionDictionary $dictionary) {}

    /**
     * Counted GA4 event names of the brand; null when the dictionary has no GA4 row yet.
     *
     * @return list<string>|null
     */
    public function countedEvents(Brand $brand): ?array
    {
        if (array_key_exists((int) $brand->id, $this->events)) {
            return $this->events[(int) $brand->id];
        }
        $rows = BrandConversionSource::query()->where('brand_id', $brand->id)->where('source', BrandConversionSource::SOURCE_GA4)->get();

        return $this->events[(int) $brand->id] = $rows->isEmpty() ? null : $rows
            ->filter(fn (BrandConversionSource $row): bool => $this->dictionary->countsAsCustomer($row))
            ->map(fn (BrandConversionSource $row): string => (string) $row->source_key)->values()->all();
    }

    /** Limits a query on an event-named table to the counted events. */
    public function constrain(Builder $query, Brand $brand): Builder
    {
        $events = $this->countedEvents($brand);

        return match (true) {
            $events === null => $query->whereNotIn('eventName', BrandConversionDictionary::GA4_ENGAGEMENT),
            $events === [] => $query->whereRaw('1 = 0'),
            default => $query->whereIn('eventName', $events),
        };
    }

    /**
     * Counted conversions per day in [from, to].
     *
     * @param  list<int>  $resourceIds
     * @return array<string, float>
     */
    public function daily(Brand $brand, array $resourceIds, string $from, string $to): array
    {
        if ($resourceIds === [] || ! SchemaState::hasTable('ga4_key_event_daily')) {
            return [];
        }
        $query = DB::table('ga4_key_event_daily')->whereIn('external_resource_id', $resourceIds)->whereBetween('reporting_date', [$from, $to]);

        return $this->constrain($query, $brand)->groupBy('reporting_date')
            ->selectRaw('reporting_date as day, sum('.DB::getQueryGrammar()->wrap('keyEvents').') as v')->get()
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->day, 0, 10) => (float) $row->v])->all();
    }

    /**
     * Counted share of all key events in [from, to]; null when the property reports none.
     *
     * @param  list<int>  $resourceIds
     */
    public function share(Brand $brand, array $resourceIds, string $from, string $to): ?float
    {
        if ($resourceIds === [] || ! SchemaState::hasTable('ga4_key_event_daily')) {
            return null;
        }
        $all = (float) DB::table('ga4_key_event_daily')->whereIn('external_resource_id', $resourceIds)
            ->whereBetween('reporting_date', [$from, $to])->sum('keyEvents');
        if ($all <= 0) {
            return null;
        }

        return min(1.0, array_sum($this->daily($brand, $resourceIds, $from, $to)) / $all);
    }
}
