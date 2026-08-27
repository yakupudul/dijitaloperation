<?php

namespace App\Services\MetaAds;

use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Operator\OperatorReportingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds client-friendly campaign inventory and entity-scoped Meta action context
 * to the professional workspace without changing collector semantics.
 *
 * Important: heterogeneous action_type values are NEVER collapsed into one
 * generic Results total. Actions remain typed at every hierarchy level.
 */
final class MetaAdsProfessionalWorkspaceEnhancer
{
    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindingResolver,
    ) {}

    /** @param array<string,mixed> $workspace @return array<string,mixed> */
    public function enhance(
        array $workspace,
        string $assetId,
        string $preset,
        ?string $start = null,
        ?string $end = null,
    ): array {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode !== MetaAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->accountId === null
        ) {
            return $workspace;
        }

        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $currency = strtoupper((string) ($workspace['currency'] ?? $binding->currency ?? 'XXX'));

        try {
            $workspace['campaigns'] = $this->mergeCompleteCampaignInventory(
                $workspace['campaigns'] ?? [],
                $binding->digitalAssetId,
                $binding->externalResourceId,
                $binding->accountId,
                $rangeStart,
                $rangeEnd,
                $currency,
            );

            $actionRows = $this->adActionRows(
                $binding->digitalAssetId,
                $binding->externalResourceId,
                $binding->accountId,
                $rangeStart,
                $rangeEnd,
            );

            $links = $this->adLinks(
                $workspace['ads'] ?? [],
                $binding->digitalAssetId,
                $binding->externalResourceId,
                $binding->accountId,
            );

            $rollups = $this->rollUpActions($actionRows, $links);
            $workspace['typed_actions'] = $this->presentActionMap($rollups['account'] ?? []);
            $workspace['campaigns'] = $this->attachActions($workspace['campaigns'] ?? [], $rollups['campaign'] ?? []);
            $workspace['adsets'] = $this->attachActions($workspace['adsets'] ?? [], $rollups['adset'] ?? []);
            $workspace['ads'] = $this->attachActions($workspace['ads'] ?? [], $rollups['ad'] ?? []);
            $workspace['creatives'] = $this->ensureAndAttachCreativeActions(
                $workspace['creatives'] ?? [],
                $workspace['ads'] ?? [],
                $rollups['creative'] ?? [],
                $currency,
            );
            $workspace['action_rollups_available'] = $actionRows !== [];
            $workspace['campaign_inventory'] = [
                'total' => count($workspace['campaigns'] ?? []),
                'with_period_activity' => collect($workspace['campaigns'] ?? [])->where('has_period_activity', true)->count(),
                'without_period_activity' => collect($workspace['campaigns'] ?? [])->where('has_period_activity', false)->count(),
            ];
        } catch (Throwable $e) {
            // Enhancements are additive. A presentation enhancement must never
            // take the already-valid professional workspace offline.
            $workspace['enhancement_error'] = $e->getMessage();
        }

        return $workspace;
    }

    /** @param list<array<string,mixed>> $existing @return list<array<string,mixed>> */
    private function mergeCompleteCampaignInventory(
        array $existing,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
        string $currency,
    ): array {
        $byId = collect($existing)->keyBy(static fn (array $row): string => (string) ($row['id'] ?? ''));

        // Complete inventory: snapshot is not filtered by selected period, so
        // paused/inactive campaigns remain visible instead of disappearing.
        if (Schema::hasTable('meta_campaign_snapshot')) {
            $snapshots = DB::table('meta_campaign_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('account_id', $accountId)
                ->get(['campaign_id', 'metadata']);

            foreach ($snapshots as $snapshot) {
                $campaignId = (string) $snapshot->campaign_id;
                $meta = $this->decodeMetadata($snapshot->metadata);
                $current = $byId->get($campaignId);

                if (! is_array($current)) {
                    $current = $this->emptyCampaign($campaignId, $currency);
                }

                $current['name'] = (string) ($meta['name'] ?? $current['name'] ?? ('Kampanya '.$campaignId));
                $current['objective'] = $meta['objective'] ?? ($current['objective'] ?? null);
                $current['status'] = $meta['status'] ?? ($current['status'] ?? 'UNKNOWN');
                $current['effective_status'] = $meta['effective_status'] ?? ($current['effective_status'] ?? null);
                $current['daily_budget'] = $meta['daily_budget'] ?? ($current['daily_budget'] ?? null);
                $current['lifetime_budget'] = $meta['lifetime_budget'] ?? ($current['lifetime_budget'] ?? null);
                $byId->put($campaignId, $current);
            }
        }

        // Full selected-period performance: intentionally NO arbitrary LIMIT.
        // This prevents campaign 201+ from being present in inventory but shown
        // with a false zero just because the legacy read method capped rows.
        if (Schema::hasTable('meta_campaign_daily')) {
            $performance = DB::table('meta_campaign_daily')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('account_id', $accountId)
                ->whereBetween('reporting_date', [$start, $end])
                ->groupBy('campaign_id')
                ->get([
                    'campaign_id',
                    DB::raw('SUM(spend) AS spend'),
                    DB::raw('SUM(impressions) AS impressions'),
                    DB::raw('SUM(clicks) AS clicks'),
                    DB::raw('MAX(currency) AS currency'),
                ]);

            foreach ($performance as $perf) {
                $campaignId = (string) $perf->campaign_id;
                $current = $byId->get($campaignId);
                if (! is_array($current)) {
                    $current = $this->emptyCampaign($campaignId, $currency);
                }

                $spend = (float) $perf->spend;
                $impressions = (int) $perf->impressions;
                $clicks = (int) $perf->clicks;
                $rowCurrency = $perf->currency !== null ? (string) $perf->currency : ($current['currency'] ?? $currency);

                $current['spend'] = round($spend, 2);
                $current['spend_display'] = $this->money($spend, (string) $rowCurrency);
                $current['impressions'] = $impressions;
                $current['clicks'] = $clicks;
                $current['ctr'] = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null;
                $current['cpc'] = $clicks > 0 ? round($spend / $clicks, 2) : null;
                $current['cpm'] = $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null;
                $current['currency'] = (string) $rowCurrency;
                $current['has_period_activity'] = $spend > 0 || $impressions > 0 || $clicks > 0;
                $byId->put($campaignId, $current);
            }
        }

        foreach ($byId as $id => $row) {
            if (! is_array($row) || $id === '') {
                continue;
            }
            if (! array_key_exists('has_period_activity', $row)) {
                $row['has_period_activity'] = ((float) ($row['spend'] ?? 0)) > 0
                    || ((int) ($row['impressions'] ?? 0)) > 0
                    || ((int) ($row['clicks'] ?? 0)) > 0;
                $byId->put($id, $row);
            }
        }

        return $byId->values()
            ->sort(function (array $a, array $b): int {
                $spendCompare = ((float) ($b['spend'] ?? 0)) <=> ((float) ($a['spend'] ?? 0));
                if ($spendCompare !== 0) {
                    return $spendCompare;
                }

                $activeA = $this->statusPriority((string) ($a['effective_status'] ?? $a['status'] ?? ''));
                $activeB = $this->statusPriority((string) ($b['effective_status'] ?? $b['status'] ?? ''));
                if ($activeA !== $activeB) {
                    return $activeA <=> $activeB;
                }

                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function emptyCampaign(string $campaignId, string $currency): array
    {
        return [
            'id' => $campaignId,
            'name' => 'Kampanya '.$campaignId,
            'objective' => null,
            'status' => 'UNKNOWN',
            'effective_status' => null,
            'daily_budget' => null,
            'lifetime_budget' => null,
            'spend' => 0.0,
            'spend_display' => $this->money(0, $currency),
            'impressions' => 0,
            'clicks' => 0,
            'link_clicks' => null,
            'outbound_clicks' => null,
            'ctr' => null,
            'cpc' => null,
            'cpm' => null,
            'currency' => $currency,
            'has_period_activity' => false,
        ];
    }

    /** @return list<array{ad_id:string,action_type:string,action_value:float,currency:?string,rows:int}> */
    private function adActionRows(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable('meta_typed_action_daily')) {
            return [];
        }

        return DB::table('meta_typed_action_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->where('entity_level', 'ad')
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('entity_id', 'action_type')
            ->get([
                'entity_id',
                'action_type',
                DB::raw('SUM(action_value) AS action_value'),
                DB::raw('MAX(currency) AS currency'),
                DB::raw('COUNT(*) AS rows'),
            ])
            ->map(static fn ($row): array => [
                'ad_id' => (string) $row->entity_id,
                'action_type' => (string) $row->action_type,
                'action_value' => (float) $row->action_value,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'rows' => (int) $row->rows,
            ])
            ->all();
    }

    /** @param list<array<string,mixed>> $workspaceAds @return array<string,array{campaign_id:?string,adset_id:?string,creative_id:?string}> */
    private function adLinks(array $workspaceAds, int $digitalAssetId, int $externalResourceId, string $accountId): array
    {
        $links = [];

        if (Schema::hasTable('meta_ad_snapshot')) {
            $query = DB::table('meta_ad_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('account_id', $accountId);
            if (Schema::hasColumn('meta_ad_snapshot', 'external_resource_id')) {
                $query->where('external_resource_id', $externalResourceId);
            }

            foreach ($query->get(['ad_id', 'campaign_id', 'adset_id', 'creative_id']) as $row) {
                $links[(string) $row->ad_id] = [
                    'campaign_id' => $row->campaign_id !== null ? (string) $row->campaign_id : null,
                    'adset_id' => $row->adset_id !== null ? (string) $row->adset_id : null,
                    'creative_id' => $row->creative_id !== null ? (string) $row->creative_id : null,
                ];
            }
        }

        foreach ($workspaceAds as $ad) {
            $adId = (string) ($ad['id'] ?? '');
            if ($adId === '') {
                continue;
            }
            $links[$adId] = [
                'campaign_id' => isset($ad['campaign_id']) && $ad['campaign_id'] !== null ? (string) $ad['campaign_id'] : ($links[$adId]['campaign_id'] ?? null),
                'adset_id' => isset($ad['adset_id']) && $ad['adset_id'] !== null ? (string) $ad['adset_id'] : ($links[$adId]['adset_id'] ?? null),
                'creative_id' => isset($ad['creative_id']) && $ad['creative_id'] !== null ? (string) $ad['creative_id'] : ($links[$adId]['creative_id'] ?? null),
            ];
        }

        return $links;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,array<string,?string>> $links @return array<string,mixed> */
    private function rollUpActions(array $rows, array $links): array
    {
        $rollups = ['account' => [], 'ad' => [], 'adset' => [], 'campaign' => [], 'creative' => []];

        foreach ($rows as $row) {
            $adId = (string) $row['ad_id'];
            $type = (string) $row['action_type'];
            $this->accumulate($rollups['account'], $type, $row);
            $rollups['ad'][$adId] ??= [];
            $this->accumulate($rollups['ad'][$adId], $type, $row);

            $link = $links[$adId] ?? [];
            foreach (['campaign_id' => 'campaign', 'adset_id' => 'adset', 'creative_id' => 'creative'] as $linkKey => $bucket) {
                $entityId = $link[$linkKey] ?? null;
                if ($entityId === null || $entityId === '') {
                    continue;
                }
                $rollups[$bucket][$entityId] ??= [];
                $this->accumulate($rollups[$bucket][$entityId], $type, $row);
            }
        }

        return $rollups;
    }

    /** @param array<string,array<string,mixed>> $target @param array<string,mixed> $row */
    private function accumulate(array &$target, string $actionType, array $row): void
    {
        $target[$actionType] ??= [
            'action_type' => $actionType,
            'action_value' => 0.0,
            'currency' => $row['currency'] ?? null,
            'rows' => 0,
        ];
        $target[$actionType]['action_value'] += (float) ($row['action_value'] ?? 0);
        $target[$actionType]['rows'] += (int) ($row['rows'] ?? 0);
        if (($target[$actionType]['currency'] ?? null) === null && ($row['currency'] ?? null) !== null) {
            $target[$actionType]['currency'] = $row['currency'];
        }
    }

    /** @param array<string,array<string,mixed>> $actionMap @return list<array<string,mixed>> */
    private function presentActionMap(array $actionMap): array
    {
        $rows = array_values(array_map(function (array $row): array {
            $type = (string) $row['action_type'];
            $labels = $this->actionLabels($type);

            return [
                'action_type' => $type,
                'label' => $labels['tr'],
                'label_tr' => $labels['tr'],
                'label_en' => $labels['en'],
                'kind' => $labels['kind'],
                'value' => round((float) $row['action_value'], 2),
                'currency' => $row['currency'] ?? null,
                'rows' => (int) ($row['rows'] ?? 0),
                'priority' => $this->actionPriority($type),
            ];
        }, $actionMap));

        usort($rows, static function (array $a, array $b): int {
            $priority = ((int) $a['priority']) <=> ((int) $b['priority']);
            return $priority !== 0 ? $priority : ((float) $b['value'] <=> (float) $a['value']);
        });

        return $rows;
    }

    /** @param list<array<string,mixed>> $entities @param array<string,array<string,array<string,mixed>>> $rollups @return list<array<string,mixed>> */
    private function attachActions(array $entities, array $rollups): array
    {
        return array_map(function (array $entity) use ($rollups): array {
            $id = (string) ($entity['id'] ?? '');
            $entity['actions'] = isset($rollups[$id]) ? $this->presentActionMap($rollups[$id]) : [];
            $entity['action_types_count'] = count($entity['actions']);

            return $entity;
        }, $entities);
    }

    /** @param list<array<string,mixed>> $creatives @param list<array<string,mixed>> $ads @param array<string,array<string,array<string,mixed>>> $rollups @return list<array<string,mixed>> */
    private function ensureAndAttachCreativeActions(array $creatives, array $ads, array $rollups, string $currency): array
    {
        $byId = collect($creatives)->keyBy(static fn (array $row): string => (string) ($row['id'] ?? ''));
        $adsByCreative = collect($ads)
            ->filter(static fn (array $ad): bool => filled($ad['creative_id'] ?? null))
            ->groupBy(static fn (array $ad): string => (string) $ad['creative_id']);

        foreach ($adsByCreative as $creativeId => $creativeAds) {
            if ($byId->has($creativeId)) {
                continue;
            }
            $spend = (float) $creativeAds->sum('spend');
            $impressions = (int) $creativeAds->sum('impressions');
            $clicks = (int) $creativeAds->sum('clicks');
            $byId->put($creativeId, [
                'id' => $creativeId,
                'name' => 'Kreatif '.$creativeId,
                'format' => '—',
                'status' => 'UNKNOWN',
                'title' => null,
                'body' => null,
                'cta' => null,
                'link_url' => null,
                'thumbnail_url' => null,
                'campaigns' => $creativeAds->pluck('campaign_name')->filter()->unique()->values()->all(),
                'ad_count' => $creativeAds->count(),
                'spend' => round($spend, 2),
                'spend_display' => $this->money($spend, $currency),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
                'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
                'video' => [],
                'derived_from_ads' => true,
            ]);
        }

        return $byId->values()->map(function (array $creative) use ($rollups): array {
            $id = (string) ($creative['id'] ?? '');
            $creative['actions'] = isset($rollups[$id]) ? $this->presentActionMap($rollups[$id]) : [];
            $creative['action_types_count'] = count($creative['actions']);

            return $creative;
        })->sortByDesc('spend')->values()->all();
    }

    /** @return array{tr:string,en:string,kind:string} */
    private function actionLabels(string $actionType): array
    {
        $value = strtolower($actionType);
        $contains = static fn (string $needle): bool => str_contains($value, $needle);

        return match (true) {
            $contains('lead') => ['tr' => 'Potansiyel Müşteri (Lead)', 'en' => 'Lead', 'kind' => 'conversion'],
            $contains('purchase') => ['tr' => 'Satın Alma', 'en' => 'Purchase', 'kind' => 'conversion'],
            $contains('messaging') || $contains('conversation') => ['tr' => 'Başlatılan Mesajlaşma', 'en' => 'Messaging Conversation', 'kind' => 'conversion'],
            $contains('schedule') || $contains('appointment') => ['tr' => 'Randevu', 'en' => 'Appointment', 'kind' => 'conversion'],
            $contains('contact') => ['tr' => 'İletişim', 'en' => 'Contact', 'kind' => 'conversion'],
            $contains('complete_registration') || $contains('registration') => ['tr' => 'Tamamlanan Kayıt', 'en' => 'Completed Registration', 'kind' => 'conversion'],
            $contains('initiate_checkout') => ['tr' => 'Ödeme Süreci Başlatma', 'en' => 'Initiated Checkout', 'kind' => 'conversion'],
            $contains('add_to_cart') => ['tr' => 'Sepete Ekleme', 'en' => 'Add to Cart', 'kind' => 'conversion'],
            $contains('landing_page_view') => ['tr' => 'Açılış Sayfası Görüntüleme', 'en' => 'Landing Page View', 'kind' => 'traffic'],
            $contains('link_click') => ['tr' => 'Bağlantı Tıklaması', 'en' => 'Link Click', 'kind' => 'traffic'],
            $contains('video_view') || $contains('video') => ['tr' => 'Video İzleme', 'en' => 'Video View', 'kind' => 'engagement'],
            $contains('post_engagement') => ['tr' => 'Gönderi Etkileşimi', 'en' => 'Post Engagement', 'kind' => 'engagement'],
            $contains('page_engagement') => ['tr' => 'Sayfa Etkileşimi', 'en' => 'Page Engagement', 'kind' => 'engagement'],
            $contains('view_content') => ['tr' => 'İçerik Görüntüleme', 'en' => 'View Content', 'kind' => 'traffic'],
            default => [
                'tr' => Str::headline(str_replace('.', '_', $actionType)),
                'en' => Str::headline(str_replace('.', '_', $actionType)),
                'kind' => 'other',
            ],
        };
    }

    private function actionPriority(string $actionType): int
    {
        $labels = $this->actionLabels($actionType);
        $value = strtolower($actionType);

        if (str_contains($value, 'purchase')) return 10;
        if (str_contains($value, 'lead')) return 20;
        if (str_contains($value, 'messaging') || str_contains($value, 'conversation')) return 30;
        if (str_contains($value, 'schedule') || str_contains($value, 'appointment')) return 40;
        if (str_contains($value, 'contact')) return 50;
        if (str_contains($value, 'complete_registration') || str_contains($value, 'registration')) return 60;
        if ($labels['kind'] === 'conversion') return 70;
        if ($labels['kind'] === 'traffic') return 150;
        if ($labels['kind'] === 'engagement') return 200;

        return 120;
    }

    private function statusPriority(string $status): int
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 0,
            'PAUSED' => 1,
            'PENDING_REVIEW', 'IN_PROCESS', 'WITH_ISSUES' => 2,
            'ARCHIVED', 'DELETED' => 4,
            default => 3,
        };
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || $raw === '') return [];

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function money(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $currency = $currency !== '' && $currency !== 'XXX' ? $currency : 'N/A';

        return $currency.' '.number_format($amount, 2);
    }
}
