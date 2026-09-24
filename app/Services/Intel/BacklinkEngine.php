<?php

namespace App\Services\Intel;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\Demand\DataForSeoIntegrationLookup;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Backlink opportunity engine (Faz 8c, DataForSEO Backlinks, live): summaries for the brand and its approved
 * competitors, the brand's referring domains (new / lost), and opportunities — domains linking to at least
 * `min_competitors` competitors but not to us, filtered by rank and spam score — plus the Turkish directory list.
 * Opportunity status (new → contacted → waiting → live / lost / rejected) is the operator's; refreshes never reset it.
 */
final class BacklinkEngine
{
    public const array STATUSES = ['new' => 'Yeni', 'contacted' => 'İletişim kuruldu', 'waiting' => 'Bekliyor', 'live' => 'Yayında', 'lost' => 'Kaybedildi', 'rejected' => 'Uygun değil'];

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
        private readonly DataForSeoTaskQueue $queue,
    ) {}

    /** @return list<string> */
    public function competitorDomains(Brand $brand): array
    {
        return SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'approved')
            ->whereNotNull('normalized_domain')->orderBy('id')->limit((int) config('moxdop-intel.backlinks.max_competitors', 5))
            ->pluck('normalized_domain')->map(fn ($d): string => mb_strtolower((string) $d))->unique()->values()->all();
    }

    public function estimate(Brand $brand): float
    {
        $requests = 2 + count($this->competitorDomains($brand)) + (count($this->competitorDomains($brand)) >= 2 ? 1 : 0);

        return round($requests * (float) config('moxdop-intel.backlinks.cost_per_request_usd', 0.03), 3);
    }

    /** @return array{referring_domains: int, new: int, lost: int, opportunities: int, spent_usd: float} */
    public function refresh(Brand $brand, ?User $actor = null): array
    {
        $integration = $this->integrations->active();
        $settings = BrandIntelSetting::for($brand);
        $domain = app(BrandGbpIdentity::class)->for($brand)['hosts'][0] ?? null;
        if ($integration === null) {
            throw ValidationException::withMessages(['backlinks' => 'DataForSEO bağlantısı yok.']);
        }
        if ($domain === null) {
            throw ValidationException::withMessages(['backlinks' => 'Markanın web sitesi yok.']);
        }
        $spent = $this->queue->spentThisMonth((int) $brand->id);
        if ($spent + $this->estimate($brand) > (float) $settings->monthly_usd) {
            throw ValidationException::withMessages(['backlinks' => sprintf('Aylık tavan aşılır: bu ay %.2f USD harcandı, yenileme ≈ %.2f USD, tavan %.2f USD.', $spent, $this->estimate($brand), (float) $settings->monthly_usd)]);
        }
        $cfg = (array) config('moxdop-intel.backlinks', []);
        $stats = ['referring_domains' => 0, 'new' => 0, 'lost' => 0, 'opportunities' => 0, 'spent_usd' => 0.0];
        $call = function (string $endpoint, array $task, ?string $subject = null) use ($integration, $brand, &$stats): ?array {
            try {
                $response = $this->client->request($integration, 'POST', $endpoint, DataForSeoApiClient::CHARGE_CLASS_PAID_CREATE, [$task]);
                $cost = (float) ($response->cost ?? 0);
                $stats['spent_usd'] += $cost;
                $this->queue->recordLive('backlinks', (int) $brand->id, $endpoint, $cost, 'backlink_target', null);

                return (array) data_get($response->tasks, '0.result.0', []);
            } catch (Throwable $exception) {
                report($exception);
                $this->queue->recordLive('backlinks', (int) $brand->id, $endpoint, 0.0, 'backlink_target', null, $exception->getMessage());

                return null;
            }
        };

        $competitors = $this->competitorDomains($brand);
        foreach (array_merge([$domain], $competitors) as $target) {
            $summary = $call(DataForSeoEndpointAllowlist::BACKLINKS_SUMMARY_LIVE, ['target' => $target, 'include_subdomains' => true]);
            if ($summary !== null) {
                DB::table('backlink_snapshots')->insert([
                    'brand_id' => $brand->id, 'target' => $target, 'is_competitor' => $target !== $domain, 'observed_on' => now()->toDateString(),
                    'rank' => $summary['rank'] ?? null, 'backlinks' => $summary['backlinks'] ?? null, 'referring_domains' => $summary['referring_domains'] ?? null,
                    'broken_backlinks' => $summary['broken_backlinks'] ?? null, 'spam_score' => $summary['backlinks_spam_score'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $ours = $call(DataForSeoEndpointAllowlist::BACKLINKS_REFERRING_DOMAINS_LIVE, [
            'target' => $domain, 'limit' => (int) ($cfg['referring_domains_limit'] ?? 200), 'order_by' => ['rank,desc'], 'exclude_internal_backlinks' => true,
        ]);
        $ourDomains = [];
        if ($ours !== null) {
            foreach ((array) ($ours['items'] ?? []) as $item) {
                $ref = mb_strtolower((string) ($item['domain'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                $ourDomains[$ref] = true;
                $exists = DB::table('backlink_referring_domains')->where('brand_id', $brand->id)->where('domain', $ref)->first();
                $row = [
                    'rank' => $item['rank'] ?? null, 'backlinks' => $item['backlinks'] ?? null, 'spam_score' => $item['backlinks_spam_score'] ?? null,
                    'dofollow' => (int) ($item['backlinks'] ?? 0) > (int) data_get($item, 'referring_links_attributes.nofollow', 0),
                    'first_seen' => isset($item['first_seen']) ? substr((string) $item['first_seen'], 0, 10) : null,
                    'lost_on' => isset($item['lost_date']) ? substr((string) $item['lost_date'], 0, 10) : null,
                    'last_seen_on' => now()->toDateString(), 'updated_at' => now(),
                ];
                if ($exists === null) {
                    DB::table('backlink_referring_domains')->insert($row + ['brand_id' => $brand->id, 'domain' => $ref, 'created_at' => now()]);
                    $stats['new']++;
                } else {
                    DB::table('backlink_referring_domains')->where('id', $exists->id)->update($row);
                }
            }
            // Seen before, not in this (complete, rank-ordered) list and never marked lost → lost now.
            if (count($ours['items'] ?? []) < (int) ($cfg['referring_domains_limit'] ?? 200)) {
                $stats['lost'] = DB::table('backlink_referring_domains')->where('brand_id', $brand->id)->whereNull('lost_on')
                    ->whereNotIn('domain', array_keys($ourDomains) ?: [''])->update(['lost_on' => now()->toDateString(), 'updated_at' => now()]);
            }
            $stats['referring_domains'] = count($ourDomains);
        }

        if (count($competitors) >= 2) {
            $targets = [];
            foreach ($competitors as $i => $competitor) {
                $targets[(string) ($i + 1)] = $competitor;
            }
            $intersection = $call(DataForSeoEndpointAllowlist::BACKLINKS_DOMAIN_INTERSECTION_LIVE, [
                'targets' => $targets, 'exclude_targets' => [$domain], 'limit' => (int) ($cfg['intersection_limit'] ?? 100), 'include_subdomains' => true,
            ]);
            foreach ((array) ($intersection['items'] ?? []) as $item) {
                $linked = array_values(array_filter((array) ($item['domain_intersection'] ?? []), 'is_array'));
                $ref = mb_strtolower((string) ($linked[0]['domain'] ?? ''));
                if ($ref === '' || isset($ourDomains[$ref]) || count($linked) < min(count($competitors), (int) ($cfg['min_competitors'] ?? 2))) {
                    continue;
                }
                $rank = (int) max(array_map(static fn (array $l): int => (int) ($l['rank'] ?? 0), $linked));
                $spam = (int) max(array_map(static fn (array $l): int => (int) ($l['backlinks_spam_score'] ?? 0), $linked));
                if ($rank < (int) ($cfg['min_rank'] ?? 50) || $spam > (int) ($cfg['max_spam_score'] ?? 30)) {
                    continue;
                }
                $this->upsertOpportunity($brand, $ref, 'intersection', [
                    'competitors_linking' => count($linked), 'competitor_domains' => json_encode(array_values(array_unique(array_map(static fn (array $l): string => (string) ($l['target'] ?? ''), $linked)))),
                    'rank' => $rank, 'spam_score' => $spam,
                ]);
                $stats['opportunities']++;
            }
        }
        $stats['opportunities'] += $this->syncCitations($brand, array_keys($ourDomains));
        $settings->forceFill(['backlinks_refreshed_at' => now()])->save();
        $stats['spent_usd'] = round($stats['spent_usd'], 4);

        return $stats;
    }

    /**
     * Turkish directories / citations for the brand's sector; ones already linking are left out.
     *
     * @param  list<string>  $referring
     */
    public function syncCitations(Brand $brand, array $referring = []): int
    {
        $sectors = $brand->sectorCodes();
        $added = 0;
        foreach ((array) config('moxdop-intel.backlinks.citations', []) as $citation) {
            $wanted = (array) ($citation['sectors'] ?? []);
            if (($wanted !== [] && array_intersect($wanted, $sectors) === []) || in_array($citation['domain'], $referring, true)) {
                continue;
            }
            $added += $this->upsertOpportunity($brand, (string) $citation['domain'], 'citation', ['name' => (string) $citation['name']]) ? 1 : 0;
        }

        return $added;
    }

    /** Scheduled: enabled brands (active customers) whose last refresh is older than every_days. */
    public function runDue(): array
    {
        $stats = ['refreshed' => 0, 'skipped' => 0];
        $every = (int) config('moxdop-intel.backlinks.every_days', 30);
        $settings = BrandIntelSetting::query()->where('backlinks_enabled', true)
            ->where(fn ($q) => $q->whereNull('backlinks_refreshed_at')->orWhere('backlinks_refreshed_at', '<', now()->subDays($every)))
            ->whereHas('brand.customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->with('brand')->get();
        foreach ($settings as $setting) {
            try {
                $this->refresh($setting->brand);
                $stats['refreshed']++;
            } catch (ValidationException) {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $values */
    private function upsertOpportunity(Brand $brand, string $domain, string $source, array $values): bool
    {
        $existing = DB::table('backlink_opportunities')->where('brand_id', $brand->id)->where('domain', $domain)->first();
        if ($existing !== null) {
            DB::table('backlink_opportunities')->where('id', $existing->id)->update($values + ['updated_at' => now()]);

            return false;
        }
        DB::table('backlink_opportunities')->insert($values + ['brand_id' => $brand->id, 'domain' => $domain, 'source' => $source, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }
}
