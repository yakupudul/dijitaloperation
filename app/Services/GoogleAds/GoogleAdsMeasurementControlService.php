<?php

namespace App\Services\GoogleAds;

use App\Models\GoogleAdsConversionBusinessMapping;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Decision-oriented Google Ads measurement control center.
 *
 * Reads only the local Google Ads data pool plus explicit MOXDOP Business Action
 * mappings. It never calls Google Ads during render and never invents CRM, GA4,
 * consent, enhanced-conversion, modeled-conversion, revenue or attribution facts.
 */
final class GoogleAdsMeasurementControlService
{
    /** @var list<string> */
    private const LOW_INTENT_PRIMARY_CATEGORIES = [
        'PAGE_VIEW',
        'OUTBOUND_CLICK',
        'ENGAGEMENT',
        'GET_DIRECTIONS',
        'DOWNLOAD',
    ];

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
        private readonly GoogleAdsPoolReadRepository $pool,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(string $assetId, ?string $start, ?string $end): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if (
            $binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->externalResourceId === null
            || $binding->customerId === null
            || ! ctype_digit($assetId)
        ) {
            return $this->emptyWorkspace('not_connected');
        }

        if (! Schema::hasTable('google_ads_conversion_action_snapshot')) {
            return $this->emptyWorkspace('conversion_action_snapshot_unavailable');
        }

        $digitalAssetId = (int) $assetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $currency = (string) ($binding->currency ?? 'XXX');

        $snapshots = $this->conversionActionSnapshots($digitalAssetId, $externalResourceId, $customerId);
        if ($snapshots === []) {
            return array_merge($this->emptyWorkspace('no_conversion_actions'), [
                'connected' => true,
                'currency' => $currency,
            ]);
        }

        $dailyByAction = [];
        if (
            filled($start)
            && filled($end)
            && Schema::hasTable('google_ads_conversion_action_daily')
        ) {
            $dailyByAction = array_column(
                $this->pool->conversionActionDailySums(
                    $digitalAssetId,
                    $externalResourceId,
                    $customerId,
                    (string) $start,
                    (string) $end,
                ),
                null,
                'conversion_action_id',
            );
        }

        $mappings = $this->businessMappings($digitalAssetId);
        $rows = collect($snapshots)
            ->map(fn (array $snapshot): array => $this->actionRow(
                $snapshot,
                $dailyByAction[(string) $snapshot['conversion_action_id']] ?? null,
                $mappings[(string) $snapshot['conversion_action_id']] ?? null,
                $currency,
            ))
            ->sortBy(fn (array $row): array => [
                $row['role'] === 'Primary' ? 0 : 1,
                $row['status'] === 'ENABLED' ? 0 : 1,
                -((float) ($row['conversions'] ?? 0)),
                $row['action'],
            ])
            ->values();

        $decisions = collect();
        foreach ($rows as $row) {
            foreach ($this->actionDecisions($row) as $decision) {
                $decisions->push($decision);
            }
        }

        $duplicateCandidates = $this->duplicateCandidates($rows);
        foreach ($duplicateCandidates as $candidate) {
            $decisions->push([
                'severity' => 'review',
                'code' => 'possible_duplicate',
                'title' => 'Possible duplicate conversion signal',
                'message' => $candidate['reason'],
                'action_id' => null,
            ]);
        }

        $decisions = $decisions
            ->sortBy(fn (array $row): array => [$this->severityPriority($row['severity']), $row['title']])
            ->values();

        $primary = $rows->where('role', 'Primary')->values();
        $secondary = $rows->where('role', 'Secondary')->values();
        $observedPrimary = $primary->where('observed', true)->values();
        $mapped = $rows->where('business_mapped', true)->values();
        $mappedPrimary = $primary->where('business_mapped', true)->values();
        $valueBearing = $rows->filter(fn (array $row): bool => is_numeric($row['conversions_value']) && (float) $row['conversions_value'] > 0)->values();
        $ga4Actions = $rows->filter(fn (array $row): bool => $this->isGa4Action($row))->values();
        $offlineActions = $rows->filter(fn (array $row): bool => $this->isOfflineAction($row))->values();
        $criticalCount = $decisions->where('severity', 'critical')->count();
        $reviewCount = $decisions->where('severity', 'review')->count();

        $health = match (true) {
            $rows->isEmpty() => 'unavailable',
            $criticalCount > 0 => 'critical',
            $reviewCount > 0 => 'review',
            $primary->isEmpty() => 'review',
            $observedPrimary->isEmpty() => 'review',
            default => 'healthy',
        };

        return [
            'connected' => true,
            'state' => 'real',
            'currency' => $currency,
            'period' => ['start' => $start, 'end' => $end],
            'health' => [
                'state' => $health,
                'critical' => $criticalCount,
                'review' => $reviewCount,
                'opportunities' => $decisions->where('severity', 'opportunity')->count(),
                'checks' => $this->healthChecks($rows, $primary, $observedPrimary, $mappedPrimary, $duplicateCandidates),
            ],
            'summary' => [
                'actions' => $rows->count(),
                'primary' => $primary->count(),
                'secondary' => $secondary->count(),
                'observed_primary' => $observedPrimary->count(),
                'mapped' => $mapped->count(),
                'mapped_primary' => $mappedPrimary->count(),
                'value_bearing' => $valueBearing->count(),
                'ga4_actions' => $ga4Actions->count(),
                'offline_actions' => $offlineActions->count(),
            ],
            'optimization_actions' => $primary->all(),
            'decision_inbox' => $decisions->all(),
            'goals' => $this->goalArchitecture($rows),
            'actions' => $rows->all(),
            'duplicate_candidates' => $duplicateCandidates,
            'business_mappings' => $mappings->values()->map(fn (GoogleAdsConversionBusinessMapping $mapping): array => $this->mappingArray($mapping))->all(),
            'mapped_stages' => $this->mappedStages($rows),
            'attribution' => $this->attributionSummary($rows),
            'windows' => $this->windowSummary($rows),
            'readiness' => [
                'enhanced_conversions' => [
                    'state' => 'unavailable',
                    'note' => 'Enhanced Conversions diagnostics are not collected into the canonical MOXDOP pool yet. No health claim is made.',
                ],
                'consent_modeling' => [
                    'state' => 'unavailable',
                    'note' => 'Consent Mode / modeled-conversion contribution is not collected yet. Provider conversions are not split into observed vs modeled here.',
                ],
                'offline_feedback' => [
                    'state' => $offlineActions->isNotEmpty() ? 'provider_action_observed' : 'unavailable',
                    'provider_actions' => $offlineActions->count(),
                    'note' => $offlineActions->isNotEmpty()
                        ? 'Offline/upload-type conversion actions exist in Google Ads, but MOXDOP does not yet claim a working CRM → Google feedback loop.'
                        : 'No offline/upload-type conversion action is evidenced in the current snapshot. CRM → Google feedback status is unavailable.',
                ],
                'ga4_reconciliation' => [
                    'state' => $ga4Actions->isNotEmpty() ? 'provider_action_observed' : 'unavailable',
                    'provider_actions' => $ga4Actions->count(),
                    'note' => $ga4Actions->isNotEmpty()
                        ? 'GA4-origin Google Ads conversion actions are visible, but cross-source GA4 event reconciliation is not yet canonical.'
                        : 'No GA4-origin conversion action is evidenced in the current snapshot. Cross-source reconciliation is unavailable.',
                ],
                'business_outcomes' => [
                    'state' => $mapped->isNotEmpty() ? 'semantic_mapping_available' : 'unavailable',
                    'mapped_actions' => $mapped->count(),
                    'note' => $mapped->isNotEmpty()
                        ? 'Business Action semantics are mapped, but CRM-qualified lead, sale and verified revenue counts are not connected yet.'
                        : 'No Business Action mappings exist yet; provider conversions cannot be treated as qualified leads, sales or verified revenue.',
                ],
            ],
            'boundaries' => [
                'provider_conversion' => 'Google Ads conversions are provider facts. They are not automatically Qualified Leads, Sales, Business Outcomes or verified revenue.',
                'primary' => 'Primary/Secondary follows conversion_action.primary_for_goal. Deprecated include_in_conversions_metric is kept only as a legacy provider field.',
                'duplicate' => 'Duplicate warnings are conservative review candidates, not proof of duplicate firing.',
                'write_boundary' => 'This control center does not change Google Ads conversion goals, bidding settings or tags.',
            ],
        ];
    }

    /** @return list<string> */
    public function validActionIds(string $assetId, ?string $start, ?string $end): array
    {
        return collect($this->workspace($assetId, $start, $end)['actions'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function conversionActionSnapshots(int $digitalAssetId, int $externalResourceId, string $customerId): array
    {
        $base = DB::table('google_ads_conversion_action_snapshot')
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId);

        $central = (clone $base)->whereNull('digital_asset_id')->exists();
        $query = $central
            ? $base->whereNull('digital_asset_id')
            : $base->where('digital_asset_id', $digitalAssetId);

        return $query
            ->orderBy('conversion_action_id')
            ->get(['conversion_action_id', 'metadata'])
            ->map(fn (object $row): array => [
                'conversion_action_id' => (string) $row->conversion_action_id,
                'metadata' => $this->decodeMetadata($row->metadata),
            ])
            ->all();
    }

    /** @return Collection<string,GoogleAdsConversionBusinessMapping> */
    private function businessMappings(int $digitalAssetId): Collection
    {
        if (! Schema::hasTable('google_ads_conversion_business_mappings')) {
            return collect();
        }

        return GoogleAdsConversionBusinessMapping::query()
            ->where('digital_asset_id', $digitalAssetId)
            ->orderBy('business_stage')
            ->get()
            ->keyBy(fn (GoogleAdsConversionBusinessMapping $mapping): string => (string) $mapping->conversion_action_id);
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed>|null $daily @return array<string,mixed> */
    private function actionRow(array $snapshot, ?array $daily, ?GoogleAdsConversionBusinessMapping $mapping, string $currency): array
    {
        $meta = $snapshot['metadata'];
        $primary = (bool) ($meta['primary_for_goal'] ?? $meta['primaryForGoal'] ?? false);
        $origin = strtoupper((string) ($meta['origin'] ?? 'UNKNOWN'));
        $type = strtoupper((string) ($meta['type'] ?? 'UNKNOWN'));
        $category = strtoupper((string) ($meta['category'] ?? 'UNKNOWN'));
        $status = strtoupper((string) ($meta['status'] ?? 'UNKNOWN'));
        $counting = strtoupper((string) ($meta['counting_type'] ?? $meta['countingType'] ?? 'UNKNOWN'));
        $conversions = is_numeric($daily['conversions'] ?? null) ? (float) $daily['conversions'] : null;
        $allConversions = is_numeric($daily['all_conversions'] ?? null) ? (float) $daily['all_conversions'] : null;
        $value = is_numeric($daily['conversions_value'] ?? null) ? (float) $daily['conversions_value'] : null;
        $observed = ($conversions !== null && $conversions > 0) || ($allConversions !== null && $allConversions > 0);

        $attributionModel = $this->metaValue($meta, [
            'attribution_model',
            'attribution_model_settings.attribution_model',
            'attributionModelSettings.attributionModel',
        ]);
        $ddaStatus = $this->metaValue($meta, [
            'data_driven_model_status',
            'attribution_model_settings.data_driven_model_status',
            'attributionModelSettings.dataDrivenModelStatus',
        ]);
        $clickWindow = $this->metaValue($meta, ['click_through_lookback_window_days', 'clickThroughLookbackWindowDays']);
        $viewWindow = $this->metaValue($meta, ['view_through_lookback_window_days', 'viewThroughLookbackWindowDays']);
        $defaultValue = $this->metaValue($meta, ['default_value', 'value_settings.default_value', 'valueSettings.defaultValue']);
        $defaultCurrency = $this->metaValue($meta, ['default_currency_code', 'value_settings.default_currency_code', 'valueSettings.defaultCurrencyCode']);
        $ga4Event = $this->metaValue($meta, ['ga4_event_name', 'google_analytics_4_settings.event_name', 'googleAnalytics4Settings.eventName']);
        $ga4Property = $this->metaValue($meta, ['ga4_property_id', 'google_analytics_4_settings.property_id', 'googleAnalytics4Settings.propertyId']);

        return [
            'id' => (string) $snapshot['conversion_action_id'],
            'action' => (string) ($meta['name'] ?? ('Action '.$snapshot['conversion_action_id'])),
            'origin' => $origin,
            'source_label' => $this->sourceLabel($origin, $type),
            'role' => $primary ? 'Primary' : 'Secondary',
            'primary_for_goal' => $primary,
            'legacy_include_in_conversions_metric' => (bool) ($meta['include_in_conversions_metric'] ?? $meta['includeInConversionsMetric'] ?? false),
            'category' => $category,
            'type' => $type,
            'status' => $status,
            'counting_type' => $counting,
            'conversions' => $conversions,
            'all_conversions' => $allConversions,
            'conversions_value' => $value,
            'observed' => $observed,
            'state' => $observed ? 'Observed' : 'No recent signal',
            'attribution_model' => $attributionModel !== null ? strtoupper((string) $attributionModel) : null,
            'data_driven_model_status' => $ddaStatus !== null ? strtoupper((string) $ddaStatus) : null,
            'click_window_days' => is_numeric($clickWindow) ? (int) $clickWindow : null,
            'view_window_days' => is_numeric($viewWindow) ? (int) $viewWindow : null,
            'default_value' => is_numeric($defaultValue) ? (float) $defaultValue : null,
            'default_currency' => $defaultCurrency !== null ? (string) $defaultCurrency : null,
            'ga4_event_name' => $ga4Event !== null ? (string) $ga4Event : null,
            'ga4_property_id' => $ga4Property !== null ? (string) $ga4Property : null,
            'business_mapped' => $mapping !== null,
            'business_mapping' => $mapping !== null ? $this->mappingArray($mapping) : null,
            'currency' => $currency,
        ];
    }

    /** @param array<string,mixed> $row @return list<array<string,mixed>> */
    private function actionDecisions(array $row): array
    {
        $out = [];
        if ($row['role'] !== 'Primary') {
            return $out;
        }

        if ($row['status'] !== 'ENABLED') {
            $out[] = $this->decision(
                'critical',
                'primary_not_enabled',
                'Primary conversion is not enabled',
                $row['action'].' is Primary for bidding but its provider status is '.$row['status'].'.',
                $row['id'],
            );
        }

        if (! $row['observed']) {
            $out[] = $this->decision(
                'review',
                'primary_no_signal',
                'Primary conversion has no recent signal',
                $row['action'].' is a bidding signal but produced no observed provider conversion in the selected period.',
                $row['id'],
            );
        }

        if (in_array($row['category'], self::LOW_INTENT_PRIMARY_CATEGORIES, true)) {
            $out[] = $this->decision(
                'review',
                'low_intent_primary',
                'Easy-action bidding signal needs review',
                $row['action'].' is Primary but its category is '.$row['category'].'. Confirm that this action represents the business outcome Google should optimize for.',
                $row['id'],
            );
        }

        if (! $row['business_mapped']) {
            $out[] = $this->decision(
                'opportunity',
                'business_mapping_missing',
                'Map Primary conversion to a Business Action',
                $row['action'].' is used for bidding but MOXDOP does not yet know whether it means Lead, Qualified Lead, Sale, Revenue or another business stage.',
                $row['id'],
            );
        }

        if (
            $row['counting_type'] === 'MANY_PER_CLICK'
            && in_array($row['category'], ['SUBMIT_LEAD_FORM', 'IMPORTED_LEAD', 'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'CONTACT', 'REQUEST_QUOTE'], true)
        ) {
            $out[] = $this->decision(
                'review',
                'lead_many_per_click',
                'Lead action counts many conversions per interaction',
                $row['action'].' uses MANY_PER_CLICK. Confirm that multiple lead conversions from one ad interaction are intentionally valuable.',
                $row['id'],
            );
        }

        return $out;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function duplicateCandidates(Collection $rows): array
    {
        $primary = $rows->where('role', 'Primary')->where('observed', true)->values();
        $candidates = [];

        foreach ($primary->groupBy('category') as $category => $group) {
            if ($category === 'UNKNOWN' || $group->count() < 2) {
                continue;
            }

            $items = $group->values()->all();
            for ($i = 0; $i < count($items); $i++) {
                for ($j = $i + 1; $j < count($items); $j++) {
                    $a = $items[$i];
                    $b = $items[$j];
                    $aConv = (float) ($a['conversions'] ?? 0);
                    $bConv = (float) ($b['conversions'] ?? 0);
                    if ($aConv <= 0 || $bConv <= 0) {
                        continue;
                    }

                    $max = max($aConv, $bConv);
                    $differenceRatio = $max > 0 ? abs($aConv - $bConv) / $max : 1.0;
                    $differentOrigins = $a['origin'] !== 'UNKNOWN' && $b['origin'] !== 'UNKNOWN' && $a['origin'] !== $b['origin'];
                    $sameNormalizedName = Str::slug((string) $a['action']) === Str::slug((string) $b['action']);

                    if (($differentOrigins && $differenceRatio <= 0.15) || $sameNormalizedName) {
                        $candidates[] = [
                            'category' => (string) $category,
                            'action_a' => $a['action'],
                            'action_b' => $b['action'],
                            'reason' => $a['action'].' and '.$b['action'].' are both Primary in '.$category.' and show similar/overlapping provider evidence. Review whether the same business event can be counted twice.',
                        ];
                    }
                }
            }
        }

        return $candidates;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function goalArchitecture(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (array $row): string => $row['category'] ?: 'UNKNOWN')
            ->map(function (Collection $group, string $category): array {
                $primary = $group->where('role', 'Primary');
                return [
                    'category' => $category,
                    'actions' => $group->count(),
                    'primary' => $primary->count(),
                    'secondary' => $group->where('role', 'Secondary')->count(),
                    'observed' => $group->where('observed', true)->count(),
                    'primary_conversions' => round((float) $primary->sum(fn (array $row): float => (float) ($row['conversions'] ?? 0)), 2),
                    'mapped_primary' => $primary->where('business_mapped', true)->count(),
                ];
            })
            ->sortByDesc('primary_conversions')
            ->values()
            ->all();
    }

    /** @param Collection<int,array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function mappedStages(Collection $rows): array
    {
        return $rows
            ->where('business_mapped', true)
            ->groupBy(fn (array $row): string => (string) data_get($row, 'business_mapping.business_stage', 'other'))
            ->map(function (Collection $group, string $stage): array {
                return [
                    'stage' => $stage,
                    'actions' => $group->count(),
                    'provider_conversions' => round((float) $group->sum(fn (array $row): float => (float) ($row['conversions'] ?? 0)), 2),
                    'note' => 'This is a semantic grouping of provider conversions, not a deduplicated CRM funnel.',
                ];
            })
            ->values()
            ->all();
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function attributionSummary(Collection $rows): array
    {
        $known = $rows->filter(fn (array $row): bool => filled($row['attribution_model']));
        return [
            'available' => $known->isNotEmpty(),
            'known' => $known->count(),
            'unknown' => $rows->count() - $known->count(),
            'models' => $known->groupBy('attribution_model')->map->count()->all(),
            'dda_available' => $known->where('data_driven_model_status', 'AVAILABLE')->count(),
            'note' => $known->isNotEmpty()
                ? 'Attribution fields are provider facts from the conversion-action snapshot.'
                : 'Attribution model fields are not present in the currently collected snapshot yet.',
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function windowSummary(Collection $rows): array
    {
        $known = $rows->filter(fn (array $row): bool => $row['click_window_days'] !== null || $row['view_window_days'] !== null);
        return [
            'available' => $known->isNotEmpty(),
            'known' => $known->count(),
            'unknown' => $rows->count() - $known->count(),
            'rows' => $known->map(fn (array $row): array => [
                'id' => $row['id'],
                'action' => $row['action'],
                'click_window_days' => $row['click_window_days'],
                'view_window_days' => $row['view_window_days'],
            ])->values()->all(),
            'note' => $known->isNotEmpty()
                ? 'Lookback windows are provider configuration facts. MOXDOP does not infer conversion lag from them.'
                : 'Conversion lookback-window fields are not present in the currently collected snapshot yet.',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function healthChecks(Collection $rows, Collection $primary, Collection $observedPrimary, Collection $mappedPrimary, array $duplicateCandidates): array
    {
        return [
            [
                'code' => 'actions_available',
                'state' => $rows->isNotEmpty() ? 'pass' : 'unknown',
                'label' => 'Conversion action inventory available',
            ],
            [
                'code' => 'primary_exists',
                'state' => $primary->isNotEmpty() ? 'pass' : 'review',
                'label' => 'At least one Primary bidding action exists',
            ],
            [
                'code' => 'primary_observed',
                'state' => $observedPrimary->isNotEmpty() ? 'pass' : 'review',
                'label' => 'Primary bidding actions produce provider signal in the selected period',
            ],
            [
                'code' => 'primary_mapped',
                'state' => $primary->isNotEmpty() && $mappedPrimary->count() === $primary->count() ? 'pass' : 'opportunity',
                'label' => 'Primary actions are mapped to MOXDOP Business Actions',
            ],
            [
                'code' => 'duplicate_review',
                'state' => $duplicateCandidates === [] ? 'pass' : 'review',
                'label' => 'No conservative duplicate-conversion candidate detected',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function mappingArray(GoogleAdsConversionBusinessMapping $mapping): array
    {
        return [
            'id' => (int) $mapping->id,
            'conversion_action_id' => (string) $mapping->conversion_action_id,
            'business_stage' => (string) $mapping->business_stage,
            'business_action_label' => $mapping->business_action_label,
            'nominal_value' => $mapping->nominal_value !== null ? (float) $mapping->nominal_value : null,
            'currency' => $mapping->currency,
            'is_quality_signal' => (bool) $mapping->is_quality_signal,
            'notes' => $mapping->notes,
        ];
    }

    /** @return array<string,mixed> */
    private function decision(string $severity, string $code, string $title, string $message, ?string $actionId): array
    {
        return compact('severity', 'code', 'title', 'message') + ['action_id' => $actionId];
    }

    private function severityPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'review' => 2,
            'opportunity' => 3,
            default => 4,
        };
    }

    /** @param array<string,mixed> $row */
    private function isGa4Action(array $row): bool
    {
        return str_contains((string) $row['origin'], 'ANALYTICS_4')
            || str_contains((string) $row['type'], 'GOOGLE_ANALYTICS_4')
            || filled($row['ga4_event_name']);
    }

    /** @param array<string,mixed> $row */
    private function isOfflineAction(array $row): bool
    {
        return str_contains((string) $row['type'], 'UPLOAD')
            || in_array((string) $row['category'], ['IMPORTED_LEAD', 'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'STORE_SALE'], true);
    }

    private function sourceLabel(string $origin, string $type): string
    {
        if (str_contains($origin, 'ANALYTICS_4') || str_contains($type, 'GOOGLE_ANALYTICS_4')) {
            return 'GA4 import';
        }
        if (str_contains($type, 'UPLOAD')) {
            return 'Offline / upload';
        }
        if (str_contains($type, 'CALL') || str_contains($origin, 'CALL')) {
            return 'Calls';
        }
        if ($origin === 'WEBSITE' || $type === 'WEBPAGE') {
            return 'Website';
        }
        if ($origin !== 'UNKNOWN') {
            return Str::headline(strtolower($origin));
        }

        return Str::headline(strtolower($type));
    }

    /** @param array<string,mixed> $meta @param list<string> $paths */
    private function metaValue(array $meta, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($meta, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
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

    /** @return array<string,mixed> */
    private function emptyWorkspace(string $state): array
    {
        return [
            'connected' => false,
            'state' => $state,
            'currency' => 'XXX',
            'period' => null,
            'health' => ['state' => 'unavailable', 'critical' => 0, 'review' => 0, 'opportunities' => 0, 'checks' => []],
            'summary' => [
                'actions' => 0,
                'primary' => 0,
                'secondary' => 0,
                'observed_primary' => 0,
                'mapped' => 0,
                'mapped_primary' => 0,
                'value_bearing' => 0,
                'ga4_actions' => 0,
                'offline_actions' => 0,
            ],
            'optimization_actions' => [],
            'decision_inbox' => [],
            'goals' => [],
            'actions' => [],
            'duplicate_candidates' => [],
            'business_mappings' => [],
            'mapped_stages' => [],
            'attribution' => ['available' => false, 'known' => 0, 'unknown' => 0, 'models' => [], 'dda_available' => 0, 'note' => null],
            'windows' => ['available' => false, 'known' => 0, 'unknown' => 0, 'rows' => [], 'note' => null],
            'readiness' => [],
            'boundaries' => [],
        ];
    }
}
