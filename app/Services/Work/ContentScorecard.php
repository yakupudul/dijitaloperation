<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Measurement\Ga4Conversions;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\ClusterLinks;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestionTypes;
use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Yazı karnesi (yakup, 2026-10-16): every new article MoxDOP sent to a site in the last DAYS, with where it stands —
 * live on the site or not, in Google's index (URL Inspection a week after it went live), and its Search Console
 * impressions, clicks and position plus GA4 key events of its landing page in 28 and 90 days. An article live a week
 * that Google has not indexed is flagged; the refresh rules (ClusterLinks) act on the numbers.
 */
final class ContentScorecard
{
    public const int DAYS = 180;

    /**
     * @return list<array{id: int, title: string, url: ?string, sent_at: ?string, live: bool, index: ?string, index_note: ?string, flag: ?string,
     *     d28: array{impressions: int, clicks: int, position: ?float, conversions: int}, d90: array{impressions: int, clicks: int, position: ?float, conversions: int}}>
     */
    public function forSite(DigitalAsset $site): array
    {
        $brand = Brand::query()->find($site->brand_id);
        $ideas = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('action->site_id', (int) $site->id)
            ->whereNotNull('action->article_write_id')->where('updated_at', '>=', now()->subDays(self::DAYS))->orderByDesc('id')->limit(200)->get()
            ->filter(fn (Suggestion $s): bool => data_get($s->action, 'kind') !== 'update')->values();
        if ($ideas->isEmpty() || $brand === null) {
            return [];
        }
        $links = app(ClusterLinks::class);
        $pages = $ideas->mapWithKeys(fn (Suggestion $s): array => [(int) $s->id => $links->livePage($s, $site)]);
        $urls = $pages->filter()->map(fn ($p): string => (string) $p->url)->values()->all();
        $index = $this->index($site, $urls);
        $search = ['d28' => $this->search($urls, 28), 'd90' => $this->search($urls, 90)];
        $conversions = ['d28' => $this->conversions($brand, $urls, 28), 'd90' => $this->conversions($brand, $urls, 90)];
        $out = [];
        foreach ($ideas as $idea) {
            $page = $pages->get((int) $idea->id);
            $key = $page !== null ? SeoText::urlKey((string) $page->url) : null;
            $state = $key !== null ? ($index[$key] ?? null) : null;
            $sentAt = $idea->resolved_at ?? $idea->updated_at;
            $row = ['id' => (int) $idea->id, 'title' => (string) $idea->title, 'url' => $page?->url, 'sent_at' => $sentAt?->toDateString(), 'live' => $page !== null,
                'index' => $state['verdict'] ?? null, 'index_note' => $state['note'] ?? null];
            foreach (['d28', 'd90'] as $window) {
                $numbers = $key !== null ? ($search[$window][$key] ?? null) : null;
                $row[$window] = ['impressions' => (int) ($numbers['impressions'] ?? 0), 'clicks' => (int) ($numbers['clicks'] ?? 0), 'position' => $numbers['position'] ?? null,
                    'conversions' => $key !== null ? (int) ($conversions[$window][rtrim(SeoText::urlPath((string) $page->url), '/')] ?? 0) : 0];
            }
            $row['flag'] = match (true) {
                $page === null && $sentAt !== null && $sentAt->lt(now()->subDays(7)) => 'Sitede yayında görünmüyor',
                $page !== null && $state !== null && $state['verdict'] !== 'PASS' => 'Google dizinde değil'.($state['note'] ? ': '.$state['note'] : ''),
                $page !== null && $sentAt !== null && $sentAt->lt(now()->subDays(90)) && $row['d90']['impressions'] === 0 => '90 günde hiç görünmedi',
                default => null,
            };
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The latest URL Inspection verdict of each page (PASS = indexed) with Google's coverage note.
     *
     * @param  list<string>  $urls
     * @return array<string, array{verdict: string, note: ?string}>
     */
    private function index(DigitalAsset $site, array $urls): array
    {
        if ($urls === [] || ! SchemaState::hasTable('gsc_url_inspection_snapshot')) {
            return [];
        }
        $wanted = collect($urls)->mapWithKeys(fn (string $u): array => [SeoText::urlKey($u) => true])->all();
        $out = [];
        DB::table('gsc_url_inspection_snapshot')->where('digital_asset_id', $site->id)->where('inspected_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('inspected_at')->limit(5000)->get(['page', 'metadata'])->each(function (object $row) use (&$out, $wanted): void {
                $key = SeoText::urlKey((string) $row->page);
                if (! isset($wanted[$key]) || isset($out[$key])) {
                    return;
                }
                $meta = is_array($row->metadata) ? $row->metadata : (json_decode((string) $row->metadata, true) ?: []);
                $out[$key] = ['verdict' => mb_strtoupper((string) ($meta['verdict'] ?? '')), 'note' => isset($meta['coverageState']) ? (string) $meta['coverageState'] : null];
            });

        return $out;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, array{impressions: int, clicks: int, position: ?float}>
     */
    private function search(array $urls, int $days): array
    {
        return PageSearchStats::forUrls($urls, $days);
    }

    /**
     * GA4 key events (the brand's conversions: appointment, WhatsApp, call) of sessions that landed on the pages.
     *
     * @param  list<string>  $urls
     * @return array<string, int> landing path => key events
     */
    private function conversions(Brand $brand, array $urls, int $days): array
    {
        $resources = SiteScope::resourceIds($brand, 'ga4');
        // Key events per landing page from landing page × source (collected; the event × landing table is not, panel
        // audit N4), scaled to the share the brand dictionary counts: the column also adds page views (audit 2).
        if ($urls === [] || $resources === [] || ! SchemaState::hasTable('ga4_landing_source_daily')) {
            return [];
        }
        try {
            $from = now()->subDays($days)->toDateString();
            $share = app(Ga4Conversions::class)->share($brand, $resources, $from, now()->toDateString()) ?? 1.0;
            if ($share < Ga4Conversions::RELIABLE_SHARE) {
                return [];
            }
            $paths = collect($urls)->map(fn (string $u): string => SeoText::urlPath($u))->flatMap(fn (string $p): array => [$p, rtrim($p, '/'), rtrim($p, '/').'/'])->unique()->values()->all();

            return DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $resources)->where('reporting_date', '>=', $from)
                ->whereIn('landingPage', $paths)->groupBy('landingPage')->selectRaw('"landingPage" as landing, sum("keyEvents") as events')->get()
                ->reduce(function (array $carry, object $r) use ($share): array {
                    $path = rtrim(SeoText::urlPath((string) $r->landing), '/');
                    $carry[$path] = ($carry[$path] ?? 0) + (int) round((float) $r->events * $share);

                    return $carry;
                }, []);
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }
}
