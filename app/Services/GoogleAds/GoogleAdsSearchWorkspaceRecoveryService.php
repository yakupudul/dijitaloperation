<?php

namespace App\Services\GoogleAds;

use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Production-only reconciliation for the Google Ads Search workspace.
 *
 * During the provider-resource migration some durable, asset-bound rows were
 * written before external_resource_id became the canonical join key. The normal
 * pool reader intentionally does not merge source modes, but older rows can
 * therefore become invisible. This service selects exactly one source mode per
 * dataset in priority order and never sums central + legacy copies together.
 *
 * It also derives a transparent MOXDOP search-intelligence baseline from real
 * provider search-term text/metrics. Derived intent/decision labels are not
 * Google Ads facts and never mutate provider data.
 */
final class GoogleAdsSearchWorkspaceRecoveryService
{
    private const string MODE_CENTRAL = 'central';
    private const string MODE_RESOURCE_LEGACY = 'resource_legacy';
    private const string MODE_PRE_RESOURCE_LEGACY = 'pre_resource_legacy';

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
    ) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function reconcile(string $assetId, string $start, string $end, array $data): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->customerId === null) {
            return $data;
        }

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $campaignNames = $this->campaignNames($data);

        $existingTerms = is_array(data_get($data, 'search.terms')) ? data_get($data, 'search.terms') : [];
        $recoveredTerms = $this->searchTerms(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $start,
            $end,
            $campaignNames,
        );
        $terms = $recoveredTerms !== [] ? $recoveredTerms : $existingTerms;

        $existingKeywords = is_array(data_get($data, 'search.keywords')) ? data_get($data, 'search.keywords') : [];
        $recoveredKeywords = $this->keywordPerformance(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $start,
            $end,
        );
        $keywords = $recoveredKeywords !== [] ? $recoveredKeywords : $existingKeywords;
        $keywordSource = $recoveredKeywords !== [] ? 'daily' : ($existingKeywords !== [] ? 'existing' : '');

        if ($keywords === []) {
            $keywords = $this->keywordInventory(
                $digitalAssetId,
                $externalResourceId,
                $customerId,
            );
            if ($keywords !== []) {
                $keywordSource = 'snapshot';
            }
        }

        if ($terms !== []) {
            [$previousStart, $previousEnd] = $this->previousRange($start, $end);
            $previousTerms = $this->searchTerms(
                $digitalAssetId,
                $externalResourceId,
                $customerId,
                $previousStart,
                $previousEnd,
                $campaignNames,
            );

            $analysis = $this->analyzeTerms($terms, $previousTerms);
            $data['search'] = array_merge(is_array($data['search'] ?? null) ? $data['search'] : [], $analysis);
            $data['search']['terms'] = $analysis['terms'];
            $data['data_provenance']['search.terms'] = 'PROVIDER_LIMITED';
            $data['data_provenance']['search.clusters'] = 'DERIVED_REAL';
            $data['data_provenance']['search.inbox'] = 'DERIVED_REAL';
        } else {
            $data['search']['terms'] = [];
            $data['search']['terms_observed'] = 0;
        }

        if ($keywords !== []) {
            $data['search']['keywords'] = $keywords;
            $data['search']['keyword_source'] = $keywordSource;
            $data['search']['keyword_note'] = $keywordSource === 'snapshot'
                ? 'Keyword inventory is real provider snapshot data. Selected-period performance is unavailable, so metric cells remain —.'
                : 'Keyword performance is read from durable provider-backed daily rows.';
            $data['data_provenance']['search.keywords'] = $keywordSource === 'snapshot' ? 'INVENTORY_REAL' : 'PROVIDER_LIMITED';
        }

        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function campaignNames(array $data): array
    {
        $names = [];
        foreach (($data['campaigns'] ?? []) as $campaign) {
            if (! is_array($campaign)) {
                continue;
            }
            $id = (string) ($campaign['id'] ?? '');
            $name = (string) ($campaign['name'] ?? '');
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /** @param array<string,string> $campaignNames @return list<array<string,mixed>> */
    private function searchTerms(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        array $campaignNames,
        int $limit = 500,
    ): array {
        $mode = $this->dailyMode('google_ads_search_term_daily', $digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($mode === null) {
            return [];
        }

        $base = $this->scope('google_ads_search_term_daily', $mode, $digitalAssetId, $externalResourceId, $customerId)
            ->whereBetween('reporting_date', [$start, $end]);

        $rows = (clone $base)
            ->select('search_term')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(cost_amount) as cost_amount')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('MAX(currency) as currency')
            ->groupBy('search_term')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $terms = $rows->pluck('search_term')->filter()->map(fn ($term): string => (string) $term)->values()->all();
        $metaByTerm = [];
        if ($terms !== []) {
            $metaRows = (clone $base)
                ->whereIn('search_term', $terms)
                ->orderByDesc('reporting_date')
                ->orderByDesc('last_collected_at')
                ->get(['search_term', 'metadata']);
            foreach ($metaRows as $row) {
                $term = (string) $row->search_term;
                if (! array_key_exists($term, $metaByTerm)) {
                    $metaByTerm[$term] = $this->decodeMetadata($row->metadata);
                }
            }
        }

        return $rows->map(function (object $row) use ($metaByTerm, $campaignNames): array {
            $term = (string) $row->search_term;
            $meta = $metaByTerm[$term] ?? [];
            $contexts = is_array($meta['contexts'] ?? null) ? $meta['contexts'] : [];
            $first = is_array($contexts[0] ?? null) ? $contexts[0] : [];
            $campaignId = isset($first['campaign_id']) ? (string) $first['campaign_id'] : null;
            $adGroupId = isset($first['ad_group_id']) ? (string) $first['ad_group_id'] : null;
            $sourceView = (string) ($meta['source_view'] ?? 'search_term_view');
            $isPmax = $sourceView === 'campaign_search_term_view'
                || strtoupper((string) ($first['advertising_channel_type'] ?? '')) === 'PERFORMANCE_MAX';

            return [
                'term' => $term,
                'campaign' => $campaignId !== null ? ($campaignNames[$campaignId] ?? 'Campaign '.$campaignId) : null,
                'campaign_id' => $campaignId,
                'ad_group' => $isPmax ? null : $adGroupId,
                'spend' => round((float) ($row->cost_amount ?? 0), 2),
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'leads' => (float) ($row->conversions ?? 0),
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'intent' => '',
                'fit' => 'Observed',
                'decision' => '',
                'is_pmax' => $isPmax,
                'provider_may_omit_terms' => (bool) ($meta['provider_may_omit_terms'] ?? true),
                'completeness' => 'PROVIDER_LIMITED',
                'search_term_note' => GoogleAdsSpecialistReadService::SEARCH_VOLUME_NOTE,
                'keyword_distinction_note' => 'Search term ≠ keyword.',
                'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
            ];
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function keywordPerformance(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 500,
    ): array {
        $mode = $this->dailyMode('google_ads_keyword_daily', $digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($mode === null) {
            return [];
        }

        $base = $this->scope('google_ads_keyword_daily', $mode, $digitalAssetId, $externalResourceId, $customerId)
            ->whereBetween('reporting_date', [$start, $end]);

        $rows = (clone $base)
            ->select('ad_group_id', 'criterion_id')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(cost_amount) as cost_amount')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('MAX(currency) as currency')
            ->groupBy('ad_group_id', 'criterion_id')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $metaByKey = [];
        $metaRows = (clone $base)
            ->orderByDesc('reporting_date')
            ->orderByDesc('last_collected_at')
            ->get(['ad_group_id', 'criterion_id', 'metadata']);
        foreach ($metaRows as $metaRow) {
            $key = (string) $metaRow->ad_group_id."\0".(string) $metaRow->criterion_id;
            if (! array_key_exists($key, $metaByKey)) {
                $metaByKey[$key] = $this->decodeMetadata($metaRow->metadata);
            }
        }

        return $rows->map(function (object $row) use ($metaByKey): array {
            $key = (string) $row->ad_group_id."\0".(string) $row->criterion_id;
            $meta = $metaByKey[$key] ?? [];

            return [
                'ad_group_id' => (string) $row->ad_group_id,
                'criterion_id' => (string) $row->criterion_id,
                'keyword' => (string) ($meta['keyword_text'] ?? $meta['text'] ?? ('Keyword '.$row->criterion_id)),
                'match' => $this->humanize((string) ($meta['match_type'] ?? $meta['matchType'] ?? 'UNKNOWN')),
                'spend' => round((float) ($row->cost_amount ?? 0), 2),
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'leads' => (float) ($row->conversions ?? 0),
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'keyword_neq_search_term' => true,
                'inventory_only' => false,
                'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
            ];
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function keywordInventory(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        int $limit = 1000,
    ): array {
        if (! Schema::hasTable('google_ads_keyword_snapshot')) {
            return [];
        }

        $mode = $this->snapshotMode('google_ads_keyword_snapshot', $digitalAssetId, $externalResourceId, $customerId);
        if ($mode === null) {
            return [];
        }

        return $this->scope('google_ads_keyword_snapshot', $mode, $digitalAssetId, $externalResourceId, $customerId)
            ->orderBy('ad_group_id')
            ->orderBy('criterion_id')
            ->limit($limit)
            ->get(['ad_group_id', 'criterion_id', 'metadata'])
            ->map(function (object $row): array {
                $meta = $this->decodeMetadata($row->metadata);

                return [
                    'ad_group_id' => (string) $row->ad_group_id,
                    'criterion_id' => (string) $row->criterion_id,
                    'keyword' => (string) ($meta['keyword_text'] ?? $meta['text'] ?? ('Keyword '.$row->criterion_id)),
                    'match' => $this->humanize((string) ($meta['match_type'] ?? $meta['matchType'] ?? 'UNKNOWN')),
                    'status' => $meta['status'] ?? null,
                    'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                    'spend' => null,
                    'clicks' => null,
                    'impressions' => null,
                    'leads' => null,
                    'currency' => null,
                    'keyword_neq_search_term' => true,
                    'inventory_only' => true,
                    'leads_note' => 'Selected-period performance unavailable; this row comes from the real provider keyword inventory snapshot.',
                ];
            })->values()->all();
    }

    /** @param list<array<string,mixed>> $terms @param list<array<string,mixed>> $previousTerms @return array<string,mixed> */
    private function analyzeTerms(array $terms, array $previousTerms): array
    {
        $enriched = [];
        $summary = ['negative' => 0, 'keyword' => 0, 'content' => 0, 'strategy' => 0];
        $reviewSpend = 0.0;
        $highIntentSpend = 0.0;
        $observedSpend = 0.0;
        $clusters = [];

        foreach ($terms as $term) {
            $text = (string) ($term['term'] ?? '');
            $intent = $this->intentFor($text);
            $spend = (float) ($term['spend'] ?? 0);
            $clicks = (int) ($term['clicks'] ?? 0);
            $conversions = (float) ($term['leads'] ?? 0);
            $decision = 'Monitor';
            $why = 'Observed provider search term; no immediate action threshold was met.';

            if ($conversions > 0) {
                $decision = 'Keyword candidate';
                $summary['keyword']++;
                $why = 'This search term produced Google Ads conversions and is worth reviewing as an explicit targeting candidate.';
            } elseif ($intent === 'Informational' && $clicks > 0) {
                $decision = 'Content opportunity';
                $summary['content']++;
                $why = 'Informational intent received paid clicks without a recorded conversion; review whether organic/content coverage can answer this demand.';
            } elseif ($spend > 0 && $clicks >= 3) {
                $decision = 'Strategy review';
                $summary['strategy']++;
                $reviewSpend += $spend;
                $why = 'This term spent budget and received multiple clicks without a recorded Google Ads conversion; review targeting, match type, ad message and landing-page fit.';
            }

            if (in_array($intent, ['Transactional', 'Price', 'Local'], true)) {
                $highIntentSpend += $spend;
            }
            $observedSpend += $spend;

            $term['intent'] = $intent;
            $term['fit'] = in_array($intent, ['Transactional', 'Price', 'Local'], true) ? 'High intent' : 'Observed';
            $term['decision'] = $decision;
            $enriched[] = $term;

            if ($decision !== 'Monitor') {
                $clusters[] = [
                    'id' => 'term-'.substr(sha1(($term['campaign_id'] ?? '')."\0".$text), 0, 16),
                    'title' => $text,
                    'campaign' => $term['campaign'] ?? null,
                    'spend' => $spend,
                    'type' => $decision,
                    'why' => $why,
                    'intent' => $intent,
                    'decision' => $decision,
                ];
            }
        }

        usort($clusters, static fn (array $a, array $b): int => ($b['spend'] <=> $a['spend']));
        $distribution = $this->intentDistribution($enriched);
        $previousDistribution = $this->intentDistribution(array_map(function (array $term): array {
            $term['intent'] = $this->intentFor((string) ($term['term'] ?? ''));
            return $term;
        }, $previousTerms));

        return [
            'terms_observed' => count($enriched),
            'aligned_high_intent_pct' => $observedSpend > 0 ? round(($highIntentSpend / $observedSpend) * 100, 1) : null,
            'review_spend' => round($reviewSpend, 2),
            'inbox_count' => count($clusters),
            'intent_distribution' => $distribution,
            'intent_drift' => $this->intentDrift($distribution, $previousDistribution),
            'reviewable_spend' => array_slice($clusters, 0, 20),
            'inbox_summary' => $summary,
            'terms' => $enriched,
            'clusters' => array_slice($clusters, 0, 100),
            'intent_provenance' => 'Derived from real provider search-term text and metrics by deterministic MOXDOP rules. Not a Google Ads fact and not an AI-generated provider metric.',
            'search_volume_note' => GoogleAdsSpecialistReadService::SEARCH_VOLUME_NOTE,
        ];
    }

    /** @param list<array<string,mixed>> $terms @return list<array{label:string,pct:float}> */
    private function intentDistribution(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $counts = [];
        foreach ($terms as $term) {
            $intent = (string) ($term['intent'] ?? 'Generic');
            $counts[$intent] = ($counts[$intent] ?? 0) + 1;
        }
        arsort($counts);
        $total = array_sum($counts);

        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = ['label' => $label, 'pct' => round(($count / max(1, $total)) * 100, 1)];
        }

        return $out;
    }

    /** @param list<array{label:string,pct:float}> $current @param list<array{label:string,pct:float}> $previous @return list<array{label:string,from:float,to:float}> */
    private function intentDrift(array $current, array $previous): array
    {
        if ($current === [] || $previous === []) {
            return [];
        }

        $currentMap = collect($current)->pluck('pct', 'label')->all();
        $previousMap = collect($previous)->pluck('pct', 'label')->all();
        $labels = array_values(array_unique([...array_keys($currentMap), ...array_keys($previousMap)]));
        $rows = [];
        foreach ($labels as $label) {
            $from = (float) ($previousMap[$label] ?? 0);
            $to = (float) ($currentMap[$label] ?? 0);
            $rows[] = ['label' => $label, 'from' => $from, 'to' => $to, 'delta' => abs($to - $from)];
        }
        usort($rows, static fn (array $a, array $b): int => $b['delta'] <=> $a['delta']);

        return array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'from' => $row['from'],
            'to' => $row['to'],
        ], array_slice($rows, 0, 8));
    }

    private function intentFor(string $term): string
    {
        $normalized = Str::lower(Str::ascii(trim($term)));
        if ($normalized === '') {
            return 'Generic';
        }

        if (preg_match('/\b(fiyat|fiyati|fiyatlari|kac tl|ne kadar|ucret|price|cost|quote|teklif)\b/u', $normalized)) {
            return 'Price';
        }
        if (preg_match('/\b(yakinimda|yakinda|near me|telefon|iletisim|adres|location|konum)\b/u', $normalized)) {
            return 'Local';
        }
        if (preg_match('/\b(nedir|ne ise|nasil|neden|niye|what|how|why|rehber|guide)\b/u', $normalized)) {
            return 'Informational';
        }
        if (preg_match('/\b(satin al|satmak|satiyorum|alan yer|alici|hizmet|randevu|siparis|buy|sell|book|order)\b/u', $normalized)) {
            return 'Transactional';
        }

        return 'Generic';
    }

    /** @return array{0:string,1:string} */
    private function previousRange(string $start, string $end): array
    {
        $startDate = CarbonImmutable::parse($start)->startOfDay();
        $endDate = CarbonImmutable::parse($end)->startOfDay();
        $days = $startDate->diffInDays($endDate) + 1;
        $previousEnd = $startDate->subDay();
        $previousStart = $previousEnd->subDays(max(0, $days - 1));

        return [$previousStart->toDateString(), $previousEnd->toDateString()];
    }

    private function dailyMode(
        string $table,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
    ): ?string {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reporting_date')) {
            return null;
        }

        foreach ([self::MODE_CENTRAL, self::MODE_RESOURCE_LEGACY, self::MODE_PRE_RESOURCE_LEGACY] as $mode) {
            if ($this->scope($table, $mode, $digitalAssetId, $externalResourceId, $customerId)
                ->whereBetween('reporting_date', [$start, $end])
                ->exists()) {
                return $mode;
            }
        }

        return null;
    }

    private function snapshotMode(
        string $table,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): ?string {
        foreach ([self::MODE_CENTRAL, self::MODE_RESOURCE_LEGACY, self::MODE_PRE_RESOURCE_LEGACY] as $mode) {
            if ($this->scope($table, $mode, $digitalAssetId, $externalResourceId, $customerId)->exists()) {
                return $mode;
            }
        }

        return null;
    }

    private function scope(
        string $table,
        string $mode,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): Builder {
        $query = DB::table($table)->where('customer_id', $customerId);
        $hasAsset = Schema::hasColumn($table, 'digital_asset_id');
        $hasResource = Schema::hasColumn($table, 'external_resource_id');

        if ($mode === self::MODE_CENTRAL) {
            if ($hasResource) {
                $query->where('external_resource_id', $externalResourceId);
            }
            if ($hasAsset) {
                $query->whereNull('digital_asset_id');
            }
            return $query;
        }

        if ($hasAsset) {
            $query->where('digital_asset_id', $digitalAssetId);
        }

        if ($hasResource) {
            if ($mode === self::MODE_RESOURCE_LEGACY) {
                $query->where('external_resource_id', $externalResourceId);
            } else {
                $query->whereNull('external_resource_id');
            }
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function humanize(string $value): string
    {
        return Str::of($value)->replace('_', ' ')->lower()->title()->toString();
    }
}
