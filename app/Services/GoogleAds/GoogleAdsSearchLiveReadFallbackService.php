<?php

namespace App\Services\GoogleAds;

use App\Models\CoreExternalResource;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsClientFactory;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Read-through safety net for the operator Search workspace.
 *
 * Search-term and keyword collection normally comes from the Data Pool. If those
 * selected-period facts are missing (for example because a recovery run is stuck),
 * this service reads the same account directly from Google Ads and supplies the
 * workspace without inventing or persisting provider data.
 */
final class GoogleAdsSearchLiveReadFallbackService
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
        private readonly GoogleAdsClientFactory $client,
    ) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function reconcile(
        string|int $assetId,
        string $requestedStart,
        string $requestedEnd,
        array $data,
        ?string $period = null,
    ): array {
        $search = is_array($data['search'] ?? null) ? $data['search'] : [];
        $existingTerms = is_array($search['terms'] ?? null) ? $search['terms'] : [];
        $existingKeywords = is_array($search['keywords'] ?? null) ? $search['keywords'] : [];
        $hasKeywordPerformance = collect($existingKeywords)
            ->contains(static fn (mixed $row): bool => is_array($row) && ($row['period_activity'] ?? false) === true);

        if ($existingTerms !== [] && $hasKeywordPerformance) {
            return $data;
        }

        $binding = $this->bindingResolver->resolve((string) $assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->externalResourceId === null
            || $binding->customerId === null) {
            return $data;
        }

        $resource = CoreExternalResource::query()
            ->with('integration')
            ->find($binding->externalResourceId);
        if (! $resource instanceof CoreExternalResource || $resource->integration === null) {
            return $data;
        }

        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = (string) ($binding->timezone ?: ($metadata['time_zone'] ?? $metadata['timezone'] ?? 'UTC'));
        $currency = strtoupper((string) ($binding->currency ?: ($metadata['currency_code'] ?? $metadata['currency'] ?? 'XXX')));
        [$start, $end] = $this->effectiveRange($requestedStart, $requestedEnd, $period, $timezone);

        $customerId = preg_replace('/\D+/', '', (string) $binding->customerId) ?? '';
        $loginCustomerId = preg_replace('/\D+/', '', (string) (
            $metadata['login_customer_id']
            ?? $metadata['manager_customer_id']
            ?? $customerId
        )) ?? $customerId;
        if ($customerId === '' || $loginCustomerId === '') {
            return $data;
        }

        try {
            $cacheKey = sprintf(
                'gads:search-live:v2:%d:%s:%s',
                (int) $binding->externalResourceId,
                $start,
                $end,
            );

            $provider = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($resource, $customerId, $loginCustomerId, $start, $end): array {
                $terms = $this->stream(
                    $resource,
                    $customerId,
                    $loginCustomerId,
                    $this->searchTermsQuery($start, $end),
                );

                // PMax search-term availability/compatibility differs by account.
                // A failure here must not hide standard Search data.
                try {
                    $pmax = $this->stream(
                        $resource,
                        $customerId,
                        $loginCustomerId,
                        $this->pmaxSearchTermsQuery($start, $end),
                    );
                } catch (Throwable) {
                    $pmax = [];
                }

                $keywords = $this->stream(
                    $resource,
                    $customerId,
                    $loginCustomerId,
                    $this->keywordQuery($start, $end),
                );

                return [
                    'terms' => $terms,
                    'pmax_terms' => $pmax,
                    'keywords' => $keywords,
                ];
            });

            if ($existingTerms === []) {
                $search['terms'] = $this->termRows(
                    [...($provider['terms'] ?? []), ...($provider['pmax_terms'] ?? [])],
                    $currency,
                );
                $search['terms_observed'] = count($search['terms']);
            }

            if (! $hasKeywordPerformance) {
                $search['keywords'] = $this->keywordRows(
                    $provider['keywords'] ?? [],
                    $existingKeywords,
                    $currency,
                );
            }

            $search['coverage'] = $this->coverage(
                $search['terms'] ?? [],
                is_array($search['coverage'] ?? null) ? $search['coverage'] : [],
            );

            $analysis = $this->analyze($search['terms'] ?? []);
            $search['expert_insights'] = $analysis;
            $search['clusters'] = $analysis['decisions'];
            $search['intent_distribution'] = $analysis['categories'];
            $search['inbox_count'] = count($analysis['decisions']);
            $search['inbox_summary'] = $analysis['summary'];
            $search['filter_options'] = $this->filterOptions($search['terms'] ?? [], $search['keywords'] ?? []);
            $search['live_read'] = [
                'active' => true,
                'source' => 'GOOGLE_ADS_API',
                'reason' => $existingTerms === [] && ! $hasKeywordPerformance
                    ? 'search_term_and_keyword_period_facts_missing'
                    : ($existingTerms === [] ? 'search_term_period_facts_missing' : 'keyword_period_facts_missing'),
                'requested_range' => ['start' => $requestedStart, 'end' => $requestedEnd],
                'provider_range' => ['start' => $start, 'end' => $end],
                'cached_minutes' => 10,
            ];
            unset($search['live_read_error']);

            $data['search'] = $search;
            $data['data_provenance']['search.live_read'] = 'GOOGLE_ADS_API_READ_THROUGH';
            $data['data_provenance']['search.terms'] = ($search['terms'] ?? []) === [] ? 'UNAVAILABLE' : 'PROVIDER_LIMITED';
            $data['data_provenance']['search.keywords'] = ($search['keywords'] ?? []) === [] ? 'UNAVAILABLE' : 'REAL';

            return $data;
        } catch (Throwable $e) {
            $search['live_read_error'] = [
                'active' => true,
                'type' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
                'provider_range' => ['start' => $start, 'end' => $end],
            ];
            $data['search'] = $search;

            return $data;
        }
    }

    /** @return array{0:string,1:string} */
    private function effectiveRange(string $start, string $end, ?string $period, string $timezone): array
    {
        $presetDays = [
            'last_7' => 7,
            'last_14' => 14,
            'last_28' => 28,
            'last_30' => 30,
            'last_90' => 90,
        ];

        if (isset($presetDays[(string) $period])) {
            $days = $presetDays[(string) $period];
            $providerEnd = CarbonImmutable::now($timezone)->startOfDay()->subDay();
            $providerStart = $providerEnd->subDays($days - 1);

            return [$providerStart->toDateString(), $providerEnd->toDateString()];
        }

        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $start, $timezone)->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $end, $timezone)->startOfDay();
        } catch (Throwable) {
            return [$start, $end];
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /** @return list<array<string,mixed>> */
    private function stream(CoreExternalResource $resource, string $customerId, string $loginCustomerId, string $query): array
    {
        $response = $this->client->searchStream(
            $resource->integration,
            $customerId,
            $query,
            $loginCustomerId,
        );

        if (! $response->successful()) {
            $message = data_get($response->json(), '0.error.message')
                ?? data_get($response->json(), 'error.message')
                ?? ('Google Ads SearchStream HTTP '.$response->status());
            throw new RuntimeException((string) $message);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Google Ads SearchStream returned a non-JSON response.');
        }

        $rows = [];
        foreach (array_is_list($json) ? $json : [$json] as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            foreach ($chunk['results'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function searchTermsQuery(string $start, string $end): string
    {
        return <<<GAQL
SELECT
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  search_term_view.search_term,
  search_term_view.status,
  segments.search_term_match_type,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM search_term_view
WHERE segments.date BETWEEN '{$start}' AND '{$end}'
GAQL;
    }

    private function pmaxSearchTermsQuery(string $start, string $end): string
    {
        return <<<GAQL
SELECT
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  campaign_search_term_view.search_term,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM campaign_search_term_view
WHERE segments.date BETWEEN '{$start}' AND '{$end}'
  AND campaign.advertising_channel_type = 'PERFORMANCE_MAX'
GAQL;
    }

    private function keywordQuery(string $start, string $end): string
    {
        return <<<GAQL
SELECT
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group_criterion.criterion_id,
  ad_group_criterion.keyword.text,
  ad_group_criterion.keyword.match_type,
  ad_group_criterion.status,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM keyword_view
WHERE segments.date BETWEEN '{$start}' AND '{$end}'
GAQL;
    }

    /** @param list<array<string,mixed>> $providerRows @return list<array<string,mixed>> */
    private function termRows(array $providerRows, string $currency): array
    {
        $grouped = [];
        foreach ($providerRows as $row) {
            $isPmax = filled(data_get($row, 'campaignSearchTermView.searchTerm'))
                || filled(data_get($row, 'campaign_search_term_view.search_term'));
            $term = trim((string) (
                data_get($row, 'searchTermView.searchTerm')
                ?? data_get($row, 'search_term_view.search_term')
                ?? data_get($row, 'campaignSearchTermView.searchTerm')
                ?? data_get($row, 'campaign_search_term_view.search_term')
                ?? ''
            ));
            if ($term === '') {
                continue;
            }

            $key = mb_strtolower($term);
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $campaignId = (string) (data_get($row, 'campaign.id') ?? '');
            $campaignName = trim((string) (data_get($row, 'campaign.name') ?? ''));
            $adGroupId = (string) (data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id') ?? '');
            $adGroupName = trim((string) (data_get($row, 'adGroup.name') ?? data_get($row, 'ad_group.name') ?? ''));
            $status = data_get($row, 'searchTermView.status') ?? data_get($row, 'search_term_view.status');
            $match = data_get($row, 'segments.searchTermMatchType') ?? data_get($row, 'segments.search_term_match_type');

            $grouped[$key] ??= [
                'term' => $term,
                'impressions' => 0,
                'clicks' => 0,
                'spend' => 0.0,
                'leads' => 0.0,
                'currency' => $currency,
                'campaign_ids' => [],
                'campaign_names' => [],
                'ad_group_ids' => [],
                'ad_group_names' => [],
                'sources' => [],
                'statuses' => [],
                'match_types' => [],
            ];

            $grouped[$key]['impressions'] += (int) ($metrics['impressions'] ?? 0);
            $grouped[$key]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $grouped[$key]['spend'] += ((float) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0)) / 1_000_000;
            $grouped[$key]['leads'] += (float) ($metrics['conversions'] ?? 0);
            if ($campaignId !== '') $grouped[$key]['campaign_ids'][] = $campaignId;
            if ($campaignName !== '') $grouped[$key]['campaign_names'][] = $campaignName;
            if ($adGroupId !== '') $grouped[$key]['ad_group_ids'][] = $adGroupId;
            if ($adGroupName !== '') $grouped[$key]['ad_group_names'][] = $adGroupName;
            $grouped[$key]['sources'][] = $isPmax ? 'Performance Max' : 'Search';
            if (filled($status)) $grouped[$key]['statuses'][] = (string) $status;
            if (filled($match)) $grouped[$key]['match_types'][] = (string) $match;
        }

        $out = [];
        foreach ($grouped as $row) {
            foreach (['campaign_ids', 'campaign_names', 'ad_group_ids', 'ad_group_names', 'sources', 'statuses', 'match_types'] as $field) {
                $row[$field] = array_values(array_unique($row[$field]));
            }
            $row['campaign'] = count($row['campaign_names']) === 1 ? $row['campaign_names'][0] : (count($row['campaign_names']) > 1 ? 'Multiple campaigns' : null);
            $row['ad_group'] = count($row['ad_group_names']) === 1 ? $row['ad_group_names'][0] : (count($row['ad_group_names']) > 1 ? 'Multiple ad groups' : null);
            $row['source'] = count($row['sources']) === 1 ? $row['sources'][0] : 'Mixed';
            $row['match_type'] = count($row['match_types']) === 1 ? $this->humanizeMatch($row['match_types'][0]) : null;
            $row['matched_keyword'] = null;
            $row['match_source'] = null;
            $row['ctr'] = $row['impressions'] > 0 ? ($row['clicks'] / $row['impressions']) * 100 : null;
            $row['avg_cpc'] = $row['clicks'] > 0 ? $row['spend'] / $row['clicks'] : null;
            $row['cvr'] = $row['clicks'] > 0 ? ($row['leads'] / $row['clicks']) * 100 : null;
            $row['cpa'] = $row['leads'] > 0 ? $row['spend'] / $row['leads'] : null;
            $row['intent'] = $this->intent($row['term']);
            $row['decision'] = $this->decision($row);
            $row['fit'] = 'Observed';
            $row['provider_limited'] = true;
            $row['live_read'] = true;
            $out[] = $row;
        }

        usort($out, static fn (array $a, array $b): int => [$b['spend'], $b['clicks'], $b['impressions']] <=> [$a['spend'], $a['clicks'], $a['impressions']]);

        return $out;
    }

    /** @param list<array<string,mixed>> $providerRows @param list<array<string,mixed>> $inventory @return list<array<string,mixed>> */
    private function keywordRows(array $providerRows, array $inventory, string $currency): array
    {
        $rows = [];
        foreach ($inventory as $row) {
            if (! is_array($row)) continue;
            $key = (string) ($row['ad_group_id'] ?? '')."\0".(string) ($row['criterion_id'] ?? '');
            if ($key !== "\0") $rows[$key] = $row;
        }

        foreach ($providerRows as $provider) {
            $criterionId = (string) (data_get($provider, 'adGroupCriterion.criterionId') ?? data_get($provider, 'ad_group_criterion.criterion_id') ?? '');
            $adGroupId = (string) (data_get($provider, 'adGroup.id') ?? data_get($provider, 'ad_group.id') ?? '');
            if ($criterionId === '' || $adGroupId === '') continue;

            $key = $adGroupId."\0".$criterionId;
            $metrics = is_array($provider['metrics'] ?? null) ? $provider['metrics'] : [];
            $spend = ((float) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0)) / 1_000_000;
            $clicks = (int) ($metrics['clicks'] ?? 0);
            $impressions = (int) ($metrics['impressions'] ?? 0);
            $conversions = (float) ($metrics['conversions'] ?? 0);
            $keyword = data_get($provider, 'adGroupCriterion.keyword.text') ?? data_get($provider, 'ad_group_criterion.keyword.text');
            $match = data_get($provider, 'adGroupCriterion.keyword.matchType') ?? data_get($provider, 'ad_group_criterion.keyword.match_type');
            $status = data_get($provider, 'adGroupCriterion.status') ?? data_get($provider, 'ad_group_criterion.status');
            $campaignId = (string) (data_get($provider, 'campaign.id') ?? '');
            $campaignName = data_get($provider, 'campaign.name');
            $adGroupName = data_get($provider, 'adGroup.name') ?? data_get($provider, 'ad_group.name');

            $rows[$key] = array_merge($rows[$key] ?? [], [
                'ad_group_id' => $adGroupId,
                'criterion_id' => $criterionId,
                'keyword' => $keyword,
                'match' => $this->humanizeMatch((string) $match),
                'status' => $status,
                'campaign_id' => $campaignId !== '' ? $campaignId : null,
                'campaign' => $campaignName,
                'ad_group' => $adGroupName,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend' => $spend,
                'leads' => $conversions,
                'currency' => $currency,
                'period_activity' => true,
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                'avg_cpc' => $clicks > 0 ? $spend / $clicks : null,
                'cvr' => $clicks > 0 ? ($conversions / $clicks) * 100 : null,
                'cpa' => $conversions > 0 ? $spend / $conversions : null,
                'live_read' => true,
            ]);
        }

        $out = array_values($rows);
        usort($out, static function (array $a, array $b): int {
            $aActive = ($a['period_activity'] ?? false) === true;
            $bActive = ($b['period_activity'] ?? false) === true;
            if ($aActive !== $bActive) return $aActive ? -1 : 1;
            return [(float) ($b['spend'] ?? -1), (int) ($b['clicks'] ?? -1)] <=> [(float) ($a['spend'] ?? -1), (int) ($a['clicks'] ?? -1)];
        });

        return $out;
    }

    /** @param list<array<string,mixed>> $terms @param array<string,mixed> $existing @return array<string,mixed> */
    private function coverage(array $terms, array $existing): array
    {
        $disclosedSpend = array_sum(array_map(static fn (array $row): float => (float) ($row['spend'] ?? 0), $terms));
        $standardSpend = array_sum(array_map(static fn (array $row): float => ($row['source'] ?? 'Search') === 'Performance Max' ? 0.0 : (float) ($row['spend'] ?? 0), $terms));
        $networkSpend = is_numeric($existing['search_network_spend'] ?? null) ? (float) $existing['search_network_spend'] : null;
        $visibility = $networkSpend !== null && $networkSpend > 0 ? max(0.0, min(100.0, ($standardSpend / $networkSpend) * 100)) : null;

        return array_merge($existing, [
            'disclosed_terms' => count($terms),
            'disclosed_spend' => $disclosedSpend,
            'standard_disclosed_spend' => $standardSpend,
            'pmax_disclosed_spend' => max(0.0, $disclosedSpend - $standardSpend),
            'visibility_pct' => $visibility,
            'unreported_spend_estimate' => $networkSpend !== null ? max(0.0, $networkSpend - $standardSpend) : null,
            'is_provider_limited' => true,
            'live_read' => true,
        ]);
    }

    /** @param list<array<string,mixed>> $terms @return array{categories:list<array<string,mixed>>,decisions:list<array<string,mixed>>,summary:array<string,int>} */
    private function analyze(array $terms): array
    {
        $categories = [];
        $decisions = [];
        $summary = ['negative' => 0, 'keyword' => 0, 'content' => 0, 'strategy' => 0];

        foreach ($terms as $row) {
            $intent = (string) ($row['intent'] ?? 'Generic / other');
            $categories[$intent] ??= ['label' => $intent, 'terms' => 0, 'impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0];
            $categories[$intent]['terms']++;
            $categories[$intent]['impressions'] += (int) ($row['impressions'] ?? 0);
            $categories[$intent]['clicks'] += (int) ($row['clicks'] ?? 0);
            $categories[$intent]['spend'] += (float) ($row['spend'] ?? 0);
            $categories[$intent]['conversions'] += (float) ($row['leads'] ?? 0);

            $decision = (string) ($row['decision'] ?? 'Monitor');
            if ($decision === 'Keep / scale') $summary['keyword']++;
            elseif ($decision === 'Review for negative') $summary['negative']++;
            elseif ($decision === 'Content opportunity') $summary['content']++;
            elseif ($decision !== 'Monitor') $summary['strategy']++;

            if ($decision !== 'Monitor') {
                $decisions[] = [
                    'id' => substr(hash('sha256', (string) ($row['term'] ?? '')), 0, 16),
                    'title' => (string) ($row['term'] ?? ''),
                    'campaign' => $row['campaign'] ?? null,
                    'spend' => (float) ($row['spend'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'conversions' => (float) ($row['leads'] ?? 0),
                    'intent' => $intent,
                    'decision' => $decision,
                    'type' => $decision === 'Review for negative' ? 'warning' : 'info',
                    'why' => $this->decisionWhy($row),
                ];
            }
        }

        $totalSpend = array_sum(array_column($categories, 'spend'));
        $categoryRows = array_values(array_map(static function (array $row) use ($totalSpend): array {
            $row['pct'] = $totalSpend > 0 ? round(($row['spend'] / $totalSpend) * 100, 1) : 0.0;
            return $row;
        }, $categories));
        usort($categoryRows, static fn (array $a, array $b): int => [$b['spend'], $b['terms']] <=> [$a['spend'], $a['terms']]);
        usort($decisions, static fn (array $a, array $b): int => [$b['spend'], $b['clicks']] <=> [$a['spend'], $a['clicks']]);

        return ['categories' => $categoryRows, 'decisions' => $decisions, 'summary' => $summary];
    }

    /** @param list<array<string,mixed>> $terms @param list<array<string,mixed>> $keywords @return array<string,mixed> */
    private function filterOptions(array $terms, array $keywords): array
    {
        $campaigns = [];
        $adGroups = [];
        $sources = [];
        foreach ($terms as $row) {
            foreach (($row['campaign_ids'] ?? []) as $i => $id) {
                $campaigns[(string) $id] = $row['campaign_names'][$i] ?? $row['campaign'] ?? ('Campaign '.$id);
            }
            foreach (($row['ad_group_ids'] ?? []) as $i => $id) {
                $adGroups[(string) $id] = ['name' => $row['ad_group_names'][$i] ?? $row['ad_group'] ?? ('Ad group '.$id), 'campaign_id' => $row['campaign_ids'][0] ?? null];
            }
            if (filled($row['source'] ?? null)) $sources[] = (string) $row['source'];
        }
        foreach ($keywords as $row) {
            if (filled($row['campaign_id'] ?? null)) $campaigns[(string) $row['campaign_id']] = $row['campaign'] ?? ('Campaign '.$row['campaign_id']);
            if (filled($row['ad_group_id'] ?? null)) $adGroups[(string) $row['ad_group_id']] = ['name' => $row['ad_group'] ?? ('Ad group '.$row['ad_group_id']), 'campaign_id' => $row['campaign_id'] ?? null];
        }

        return [
            'campaigns' => array_values(array_map(static fn (string $name, string|int $id): array => ['id' => (string) $id, 'name' => $name], $campaigns, array_keys($campaigns))),
            'ad_groups' => array_values(array_map(static fn (array $row, string|int $id): array => ['id' => (string) $id, 'name' => (string) $row['name'], 'campaign_id' => $row['campaign_id']], $adGroups, array_keys($adGroups))),
            'sources' => array_values(array_unique($sources)),
        ];
    }

    private function humanizeMatch(string $value): ?string
    {
        return match (strtoupper(trim($value))) {
            'EXACT' => 'Exact', 'PHRASE' => 'Phrase', 'BROAD' => 'Broad',
            'NEAR_EXACT' => 'Near exact', 'NEAR_PHRASE' => 'Near phrase', 'AI_MAX' => 'AI Max',
            '', 'UNKNOWN', 'UNSPECIFIED' => null,
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function intent(string $term): string
    {
        $value = mb_strtolower($term);
        if (preg_match('/\b(fiyat|fiyatı|fiyatları|kaç para|ne kadar|ucuz|ücret|price|cost)\b/u', $value)) return 'Price';
        if (preg_match('/\b(yakın|yakınımda|nerede|bornova|izmir|manisa|karşıyaka|bayraklı|konak|buca|near me|nearby)\b/u', $value)) return 'Local';
        if (preg_match('/\b(nedir|nasıl|neden|ne demek|kaç kg|kaç kilo|how|what|why|guide)\b/u', $value)) return 'Informational';
        if (preg_match('/\b(alım|satım|satılık|alınır|alıcı|hurdacı|hurdacılık|teklif|ara|sat|buy|sell|quote)\b/u', $value)) return 'Transactional';
        return 'Generic / other';
    }

    /** @param array<string,mixed> $row */
    private function decision(array $row): string
    {
        $conversions = (float) ($row['leads'] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        $intent = (string) ($row['intent'] ?? 'Generic / other');
        if ($conversions > 0) return 'Keep / scale';
        if ($spend > 0 && $clicks >= 2) return 'Review for negative';
        if ($intent === 'Informational' && $clicks > 0) return 'Content opportunity';
        if ($impressions >= 20 && $clicks === 0) return 'Low engagement';
        return 'Monitor';
    }

    /** @param array<string,mixed> $row */
    private function decisionWhy(array $row): string
    {
        return match ((string) ($row['decision'] ?? 'Monitor')) {
            'Keep / scale' => 'This disclosed query produced provider conversions in the selected period.',
            'Review for negative' => 'This disclosed query consumed spend and received multiple clicks without a provider conversion; review relevance before adding any negative.',
            'Content opportunity' => 'This informational query received paid traffic; consider whether organic/content coverage should support the demand.',
            'Low engagement' => 'This disclosed query generated repeated impressions without clicks; review relevance, ad message, and targeting.',
            default => 'Monitor with more data before taking action.',
        };
    }
}
