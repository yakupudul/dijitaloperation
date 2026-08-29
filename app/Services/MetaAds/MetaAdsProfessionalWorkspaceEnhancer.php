<?php

namespace App\Services\MetaAds;

use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Operator\OperatorReportingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Presentation-only enrichment for the professional Meta Ads workspace.
 *
 * The collector remains the source of truth. This class:
 * - completes the campaign inventory without an arbitrary row cap,
 * - reconciles campaign/ad-set/ad names from snapshots,
 * - rolls ad-grain typed actions up to account/campaign/ad-set/creative,
 * - assigns exact, human-readable semantics to known Meta action types.
 *
 * Heterogeneous action types are never summed into one generic "Results" metric.
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

            $workspace = $this->reconcileHierarchyNames($workspace);

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
            $accountActions = $this->presentActionMap($rollups['account'] ?? []);
            $workspace['typed_actions'] = $accountActions;
            $workspace['headline_actions'] = $this->summaryActions($accountActions);

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
            // Presentation enrichment must not take an otherwise valid workspace offline.
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

                $current['name'] = (string) ($meta['name'] ?? $current['name'] ?? ('Campaign '.$campaignId));
                $current['objective'] = $meta['objective'] ?? ($current['objective'] ?? null);
                $current['status'] = $meta['status'] ?? ($current['status'] ?? 'UNKNOWN');
                $current['effective_status'] = $meta['effective_status'] ?? ($current['effective_status'] ?? null);
                $current['daily_budget'] = $meta['daily_budget'] ?? ($current['daily_budget'] ?? null);
                $current['lifetime_budget'] = $meta['lifetime_budget'] ?? ($current['lifetime_budget'] ?? null);
                $byId->put($campaignId, $current);
            }
        }

        // No LIMIT: every campaign in the selected account/range gets its real aggregate.
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

                $statusA = $this->statusPriority((string) ($a['effective_status'] ?? $a['status'] ?? ''));
                $statusB = $this->statusPriority((string) ($b['effective_status'] ?? $b['status'] ?? ''));
                if ($statusA !== $statusB) {
                    return $statusA <=> $statusB;
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
            'name' => 'Campaign '.$campaignId,
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

    /** @param array<string,mixed> $workspace @return array<string,mixed> */
    private function reconcileHierarchyNames(array $workspace): array
    {
        $campaignNames = collect($workspace['campaigns'] ?? [])
            ->filter(static fn (array $row): bool => filled($row['id'] ?? null))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();

        $adsets = array_map(static function (array $row) use ($campaignNames): array {
            $campaignId = isset($row['campaign_id']) && $row['campaign_id'] !== null ? (string) $row['campaign_id'] : null;
            if ($campaignId !== null && isset($campaignNames[$campaignId])) {
                $row['campaign_name'] = $campaignNames[$campaignId];
            }

            return $row;
        }, $workspace['adsets'] ?? []);

        $adsetNames = collect($adsets)
            ->filter(static fn (array $row): bool => filled($row['id'] ?? null))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();

        $ads = array_map(static function (array $row) use ($campaignNames, $adsetNames): array {
            $campaignId = isset($row['campaign_id']) && $row['campaign_id'] !== null ? (string) $row['campaign_id'] : null;
            $adsetId = isset($row['adset_id']) && $row['adset_id'] !== null ? (string) $row['adset_id'] : null;

            if ($campaignId !== null && isset($campaignNames[$campaignId])) {
                $row['campaign_name'] = $campaignNames[$campaignId];
            }
            if ($adsetId !== null && isset($adsetNames[$adsetId])) {
                $row['adset_name'] = $adsetNames[$adsetId];
            }

            return $row;
        }, $workspace['ads'] ?? []);

        $workspace['adsets'] = $adsets;
        $workspace['ads'] = $ads;

        return $workspace;
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
            $descriptor = $this->actionDescriptor($type);

            return [
                'action_type' => $type,
                'label' => $descriptor['tr'],
                'label_tr' => $descriptor['tr'],
                'label_en' => $descriptor['en'],
                'kind' => $descriptor['kind'],
                'family' => $descriptor['family'],
                'summary' => $descriptor['summary'],
                'description_tr' => $descriptor['description_tr'],
                'description_en' => $descriptor['description_en'],
                'value' => round((float) $row['action_value'], 2),
                'currency' => $row['currency'] ?? null,
                'rows' => (int) ($row['rows'] ?? 0),
                'priority' => $descriptor['priority'],
            ];
        }, $actionMap));

        usort($rows, static function (array $a, array $b): int {
            $priority = ((int) $a['priority']) <=> ((int) $b['priority']);

            return $priority !== 0 ? $priority : ((float) $b['value'] <=> (float) $a['value']);
        });

        return $rows;
    }

    /** @param list<array<string,mixed>> $actions @return list<array<string,mixed>> */
    private function summaryActions(array $actions): array
    {
        $out = [];
        $families = [];

        foreach ($actions as $action) {
            if (! ($action['summary'] ?? false)) {
                continue;
            }

            $family = (string) ($action['family'] ?? $action['action_type'] ?? '');
            if ($family !== '' && isset($families[$family])) {
                continue;
            }

            if ($family !== '') {
                $families[$family] = true;
            }

            $out[] = $action;
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $entities @param array<string,array<string,array<string,mixed>>> $rollups @return list<array<string,mixed>> */
    private function attachActions(array $entities, array $rollups): array
    {
        return array_map(function (array $entity) use ($rollups): array {
            $id = (string) ($entity['id'] ?? '');
            $actions = isset($rollups[$id]) ? $this->presentActionMap($rollups[$id]) : [];

            $entity['actions'] = $actions;
            $entity['summary_actions'] = $this->summaryActions($actions);
            $entity['action_types_count'] = count($actions);

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
            $spend = (float) $creativeAds->sum('spend');
            $impressions = (int) $creativeAds->sum('impressions');
            $clicks = (int) $creativeAds->sum('clicks');
            $campaignNames = $creativeAds->pluck('campaign_name')->filter()->unique()->values()->all();
            $firstAdName = $creativeAds->pluck('name')
                ->filter(static fn ($name): bool => is_string($name) && $name !== '' && ! str_starts_with($name, 'Ad '))
                ->first();

            if (! $byId->has($creativeId)) {
                $byId->put($creativeId, [
                    'id' => $creativeId,
                    'name' => is_string($firstAdName) ? $firstAdName : ('Creative '.$creativeId),
                    'format' => '—',
                    'status' => null,
                    'title' => null,
                    'body' => null,
                    'cta' => null,
                    'link_url' => null,
                    'thumbnail_url' => null,
                    'campaigns' => $campaignNames,
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

                continue;
            }

            $creative = $byId->get($creativeId);
            if (! is_array($creative)) {
                continue;
            }

            if ($campaignNames !== []) {
                $creative['campaigns'] = $campaignNames;
            }

            $currentName = trim((string) ($creative['name'] ?? ''));
            if (($currentName === '' || preg_match('/^(Creative|Kreatif)\s+\d+$/i', $currentName) === 1) && is_string($firstAdName)) {
                $creative['name'] = $firstAdName;
            }

            if (strtoupper((string) ($creative['status'] ?? '')) === 'UNKNOWN') {
                $creative['status'] = null;
            }

            $byId->put($creativeId, $creative);
        }

        return $byId->values()->map(function (array $creative) use ($rollups): array {
            $id = (string) ($creative['id'] ?? '');
            $actions = isset($rollups[$id]) ? $this->presentActionMap($rollups[$id]) : [];

            $creative['actions'] = $actions;
            $creative['summary_actions'] = $this->summaryActions($actions);
            $creative['action_types_count'] = count($actions);

            return $creative;
        })->sortByDesc('spend')->values()->all();
    }

    /**
     * Exact mappings come before fuzzy fallbacks. This prevents strings such as
     * "offsite_content_view_add_meta_leads" from being mislabeled as a Lead and
     * "messaging_block" from being mislabeled as a started conversation.
     *
     * @return array{tr:string,en:string,kind:string,family:string,summary:bool,priority:int,description_tr:string,description_en:string}
     */
    private function actionDescriptor(string $actionType): array
    {
        $value = strtolower(trim($actionType));

        $exact = match ($value) {
            'lead' => $this->descriptor(
                'Potansiyel Müşteri (Lead)', 'Lead', 'conversion', 'lead', true, 20,
                'Meta’nın toplam lead metriğidir. CRM doğrulaması yapılmadan kaliteli lead kabul edilmez.',
                'Meta total-lead metric. It is not treated as a qualified lead without CRM validation.',
            ),
            'onsite_conversion.lead_grouped', 'leadgen_grouped' => $this->descriptor(
                'Meta Üzerindeki Leadler', 'On-Facebook Leads', 'conversion', 'lead', true, 21,
                'Meta yüzeyleri üzerinde oluşturulan leadleri gösterir; toplam Lead metriğiyle aynı olayları içerebilir.',
                'Leads created on Meta surfaces; it may overlap with the total Lead metric.',
            ),
            'offsite_content_view_add_meta_leads' => $this->descriptor(
                'Meta Leads Kaynaklı İçerik Görüntüleme', 'Content Views Attributed to Meta Leads', 'traffic', 'meta_lead_content_view', false, 330,
                'Lead değildir; Meta Leads ile ilişkilendirilen bir içerik görüntüleme sinyalidir.',
                'Not a lead; this is a content-view signal attributed to Meta Leads.',
            ),
            'offsite_search_add_meta_leads' => $this->descriptor(
                'Meta Leads Kaynaklı Arama', 'Searches Attributed to Meta Leads', 'traffic', 'meta_lead_search', false, 331,
                'Lead değildir; Meta Leads ile ilişkilendirilen bir arama davranışıdır.',
                'Not a lead; this is a search action attributed to Meta Leads.',
            ),
            'offsite_complete_registration_add_meta_leads' => $this->descriptor(
                'Meta Leads Kaynaklı Tamamlanan Kayıt', 'Completed Registrations Attributed to Meta Leads', 'conversion', 'registration', false, 125,
                'Meta Leads ile ilişkilendirilen tamamlanmış kayıt olayını gösterir; doğrudan Lead sayısı değildir.',
                'A completed-registration event attributed to Meta Leads; it is not a direct lead count.',
            ),

            'onsite_conversion.messaging_conversation_started_7d' => $this->descriptor(
                'Başlatılan Mesajlaşmalar', 'Messaging Conversations Started', 'conversion', 'messaging', true, 30,
                'Reklam etkileşiminden sonraki 7 gün içinde başlatılan mesajlaşmaları gösterir.',
                'Messaging conversations started within 7 days of an ad interaction.',
            ),
            'onsite_conversion.messaging_first_reply' => $this->descriptor(
                'Yeni Mesajlaşmalar', 'New Messaging Conversations', 'conversion', 'messaging', true, 31,
                'Bir kullanıcının işletmeye ilk yanıtıyla oluşan yeni mesajlaşma bağlantısını gösterir.',
                'New messaging connections represented by the user’s first reply to the business.',
            ),
            'onsite_conversion.total_messaging_connection' => $this->descriptor(
                'Toplam Mesajlaşma Bağlantısı', 'Total Messaging Connections', 'conversion', 'messaging', true, 32,
                'Meta’nın toplam mesajlaşma bağlantısı metriğidir; başlatılan konuşma metriğiyle aynı şey değildir.',
                'Meta total messaging-connections metric; it is distinct from conversations started.',
            ),
            'onsite_conversion.messaging_conversation_replied_7d' => $this->descriptor(
                '7 Gün İçinde Yanıtlanan Mesajlaşmalar', 'Messaging Conversations Replied Within 7 Days', 'engagement', 'messaging_reply', false, 140,
                'Kullanıcının işletmeye 7 gün içinde yanıt verdiği konuşmaları gösterir.',
                'Conversations where the user replied to the business within 7 days.',
            ),
            'onsite_conversion.messaging_user_depth_2_message_send',
            'onsite_conversion.messaging_user_conversation_depth_2_message_send' => $this->descriptor(
                '2+ Mesaja Ulaşan Konuşmalar', 'Conversations Reaching 2+ Messages', 'engagement', 'messaging_depth_2', false, 150,
                'Konuşmada en az iki mesaj seviyesine ulaşan kullanıcı davranışını gösterir.',
                'Messaging behavior that reached at least two messages in the conversation.',
            ),
            'onsite_conversion.messaging_user_depth_3_message_send',
            'onsite_conversion.messaging_user_conversation_depth_3_message_send' => $this->descriptor(
                '3+ Mesaja Ulaşan Konuşmalar', 'Conversations Reaching 3+ Messages', 'engagement', 'messaging_depth_3', false, 151,
                'Konuşmada en az üç mesaj seviyesine ulaşan kullanıcı davranışını gösterir.',
                'Messaging behavior that reached at least three messages in the conversation.',
            ),
            'onsite_conversion.messaging_user_depth_5_message_send',
            'onsite_conversion.messaging_user_conversation_depth_5_message_send' => $this->descriptor(
                '5+ Mesaja Ulaşan Konuşmalar', 'Conversations Reaching 5+ Messages', 'engagement', 'messaging_depth_5', false, 152,
                'Konuşmada en az beş mesaj seviyesine ulaşan kullanıcı davranışını gösterir.',
                'Messaging behavior that reached at least five messages in the conversation.',
            ),
            'onsite_conversion.messaging_block' => $this->descriptor(
                'Engellenen Mesajlaşmalar', 'Blocked Messaging Conversations', 'negative', 'messaging_block', false, 900,
                'Kullanıcının mesajlaşma kanalında işletmeyi engellediği olayları gösterir; olumlu sonuç değildir.',
                'Conversations where the user blocked the business; this is not a positive outcome.',
            ),

            'link_click' => $this->descriptor(
                'Bağlantı Tıklamaları', 'Link Clicks', 'traffic', 'link_click', true, 220,
                'Reklam içindeki bir bağlantıya yapılan tıklamaları gösterir.',
                'Clicks on a link inside the ad.',
            ),
            'landing_page_view' => $this->descriptor(
                'Açılış Sayfası Görüntülemeleri', 'Landing Page Views', 'traffic', 'landing_page_view', true, 210,
                'Tıklama sonrasında açılış sayfasının gerçekten yüklendiğini gösterir.',
                'Shows that the landing page actually loaded after a click.',
            ),
            'omni_landing_page_view' => $this->descriptor(
                'Tüm Kanallarda Açılış Sayfası Görüntülemeleri', 'Omni Landing Page Views', 'traffic', 'landing_page_view', false, 211,
                'Meta’nın kanallar arası açılış sayfası görüntüleme varyantıdır.',
                'Meta’s cross-surface landing-page-view variant.',
            ),
            'video_view' => $this->descriptor(
                'Video İzlemeleri', 'Video Views', 'engagement', 'video_view', true, 260,
                'Meta’nın reklam videosu için raporladığı video izleme aksiyonlarını gösterir.',
                'Video-view actions reported by Meta for the ad.',
            ),
            'post_engagement' => $this->descriptor(
                'Gönderi Etkileşimleri', 'Post Engagements', 'engagement', 'post_engagement', true, 250,
                'Reklam gönderisiyle gerçekleşen toplam etkileşimleri gösterir.',
                'Total engagement actions with the ad post.',
            ),
            'page_engagement' => $this->descriptor(
                'Sayfa Etkileşimleri', 'Page Engagements', 'engagement', 'page_engagement', true, 251,
                'Sayfa ile ilişkilendirilen etkileşimleri gösterir.',
                'Engagement actions attributed to the Page.',
            ),
            'post_reaction' => $this->descriptor(
                'Gönderi Reaksiyonları', 'Post Reactions', 'engagement', 'post_reaction', false, 310,
                'Beğen, sev, haha ve benzeri gönderi reaksiyonlarını gösterir.',
                'Reactions such as Like, Love, Haha and others on the post.',
            ),
            'post' => $this->descriptor(
                'Gönderi Paylaşımları', 'Post Shares', 'engagement', 'post_share', false, 311,
                'Reklam gönderisinin paylaşılma aksiyonlarını gösterir.',
                'Share actions on the ad post.',
            ),
            'comment' => $this->descriptor(
                'Yorumlar', 'Comments', 'engagement', 'comment', false, 312,
                'Reklam gönderisine yapılan yorumları gösterir.',
                'Comments made on the ad post.',
            ),
            'onsite_conversion.post_save' => $this->descriptor(
                'Gönderi Kaydetmeleri', 'Post Saves', 'engagement', 'post_save', false, 313,
                'Gönderinin kullanıcılar tarafından kaydedilme aksiyonlarını gösterir.',
                'Times users saved the post.',
            ),
            'onsite_conversion.post_unsave' => $this->descriptor(
                'Kaydı Kaldırmalar', 'Post Unsaves', 'engagement', 'post_unsave', false, 314,
                'Daha önce kaydedilmiş gönderinin kayıttan çıkarılmasını gösterir.',
                'Times a previously saved post was unsaved.',
            ),
            'onsite_conversion.post_net_save' => $this->descriptor(
                'Net Gönderi Kaydetmeleri', 'Net Post Saves', 'engagement', 'post_net_save', false, 315,
                'Kaydetme ve kaydı kaldırma hareketlerinin net sonucunu gösterir.',
                'Net result of post-save and unsave actions.',
            ),
            'onsite_conversion.post_net_like' => $this->descriptor(
                'Net Beğeniler', 'Net Likes', 'engagement', 'post_net_like', false, 316,
                'Beğeni ve beğenmekten vazgeçme hareketlerinin net sonucunu gösterir.',
                'Net result of likes and unlikes.',
            ),
            'onsite_conversion.post_unlike' => $this->descriptor(
                'Beğenmekten Vazgeçmeler', 'Post Unlikes', 'engagement', 'post_unlike', false, 317,
                'Kullanıcının daha önce verdiği beğeniyi geri çektiği aksiyonları gösterir.',
                'Times users removed a previous like.',
            ),
            'onsite_conversion.post_net_comment' => $this->descriptor(
                'Net Yorumlar', 'Net Comments', 'engagement', 'post_net_comment', false, 318,
                'Meta’nın net yorum değişimini raporladığı metriktir.',
                'Meta metric for the net change in comments.',
            ),
            'post_interaction_gross' => $this->descriptor(
                'Toplam Gönderi Etkileşimi', 'Gross Post Interactions', 'engagement', 'post_interaction', false, 319,
                'Meta’nın brüt gönderi etkileşimi metriğidir.',
                'Meta gross post-interaction metric.',
            ),
            'post_interaction_net' => $this->descriptor(
                'Net Gönderi Etkileşimi', 'Net Post Interactions', 'engagement', 'post_interaction', false, 320,
                'Meta’nın net gönderi etkileşimi metriğidir.',
                'Meta net post-interaction metric.',
            ),
            default => null,
        };

        if (is_array($exact)) {
            return $exact;
        }

        return match (true) {
            str_contains($value, 'purchase') => $this->descriptor(
                'Satın Alma', 'Purchase', 'conversion', 'purchase', true, 10,
                'Meta tarafından satın alma olarak raporlanan dönüşüm olayıdır.',
                'A conversion event Meta reports as a purchase.',
            ),
            str_contains($value, 'complete_registration') || str_contains($value, 'registration') => $this->descriptor(
                'Tamamlanan Kayıt', 'Completed Registration', 'conversion', 'registration', true, 60,
                'Kayıt sürecinin tamamlandığını gösteren dönüşüm olayıdır.',
                'A conversion event showing registration completion.',
            ),
            str_contains($value, 'schedule') || str_contains($value, 'appointment') => $this->descriptor(
                'Randevu', 'Appointment', 'conversion', 'appointment', true, 50,
                'Randevu oluşturma veya planlama aksiyonunu gösterir.',
                'An appointment or scheduling action.',
            ),
            str_contains($value, 'contact') => $this->descriptor(
                'İletişim', 'Contact', 'conversion', 'contact', true, 55,
                'Kullanıcının işletmeyle iletişim kurma dönüşümünü gösterir.',
                'A conversion indicating contact with the business.',
            ),
            str_contains($value, 'initiate_checkout') => $this->descriptor(
                'Ödeme Süreci Başlatma', 'Initiated Checkout', 'conversion', 'checkout', true, 75,
                'Kullanıcının ödeme sürecini başlattığını gösterir.',
                'Shows that the user initiated checkout.',
            ),
            str_contains($value, 'add_to_cart') => $this->descriptor(
                'Sepete Ekleme', 'Add to Cart', 'conversion', 'add_to_cart', true, 80,
                'Kullanıcının ürünü sepete eklediğini gösterir.',
                'Shows that the user added a product to cart.',
            ),
            str_contains($value, 'lead') && ! str_contains($value, 'add_meta_leads') => $this->descriptor(
                'Potansiyel Müşteri (Lead)', 'Lead', 'conversion', 'lead', true, 25,
                'Meta tarafından lead olarak raporlanan dönüşüm olayıdır.',
                'A conversion event Meta reports as a lead.',
            ),
            str_contains($value, 'messaging') || str_contains($value, 'conversation') => $this->descriptor(
                'Mesajlaşma Aksiyonu', 'Messaging Action', 'other', 'messaging_other', false, 400,
                'Mesajlaşmayla ilgili teknik bir Meta aksiyonudur; özel anlamı doğrulanmadan “başlatılan mesajlaşma” sayılmaz.',
                'A technical Meta messaging action; it is not treated as a started conversation without a verified semantic mapping.',
            ),
            str_contains($value, 'landing_page_view') => $this->descriptor(
                'Açılış Sayfası Görüntülemeleri', 'Landing Page Views', 'traffic', 'landing_page_view', true, 210,
                'Açılış sayfasının yüklenme sinyalidir.',
                'A landing-page load signal.',
            ),
            str_contains($value, 'link_click') => $this->descriptor(
                'Bağlantı Tıklamaları', 'Link Clicks', 'traffic', 'link_click', true, 220,
                'Reklamdaki bağlantılara yapılan tıklamaları gösterir.',
                'Clicks on links inside the ad.',
            ),
            str_contains($value, 'video_view') || str_contains($value, 'video') => $this->descriptor(
                'Video İzlemeleri', 'Video Views', 'engagement', 'video_view', true, 260,
                'Video izleme davranışını gösteren Meta aksiyonudur.',
                'A Meta action representing video viewing behavior.',
            ),
            str_contains($value, 'post_engagement') => $this->descriptor(
                'Gönderi Etkileşimleri', 'Post Engagements', 'engagement', 'post_engagement', true, 250,
                'Reklam gönderisiyle gerçekleşen etkileşimleri gösterir.',
                'Engagement actions with the ad post.',
            ),
            str_contains($value, 'page_engagement') => $this->descriptor(
                'Sayfa Etkileşimleri', 'Page Engagements', 'engagement', 'page_engagement', true, 251,
                'Sayfa etkileşimlerini gösterir.',
                'Page engagement actions.',
            ),
            str_contains($value, 'view_content') => $this->descriptor(
                'İçerik Görüntüleme', 'View Content', 'traffic', 'view_content', true, 230,
                'Kullanıcının hedef içerik sayfasını görüntülediğini gösterir.',
                'Shows that the user viewed the target content.',
            ),
            default => $this->descriptor(
                Str::headline(str_replace('.', '_', $actionType)),
                Str::headline(str_replace('.', '_', $actionType)),
                'other',
                'other:'.$value,
                false,
                500,
                'Meta tarafından raporlanan ayrı bir teknik aksiyon türüdür.',
                'A distinct technical action type reported by Meta.',
            ),
        };
    }

    /** @return array{tr:string,en:string,kind:string,family:string,summary:bool,priority:int,description_tr:string,description_en:string} */
    private function descriptor(
        string $tr,
        string $en,
        string $kind,
        string $family,
        bool $summary,
        int $priority,
        string $descriptionTr,
        string $descriptionEn,
    ): array {
        return [
            'tr' => $tr,
            'en' => $en,
            'kind' => $kind,
            'family' => $family,
            'summary' => $summary,
            'priority' => $priority,
            'description_tr' => $descriptionTr,
            'description_en' => $descriptionEn,
        ];
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
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }

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
