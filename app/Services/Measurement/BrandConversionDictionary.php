<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Services\SeoTasks\SeoText;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand conversion dictionary. Discovers the conversion signals the brand's accounts actually report —
 * GA4 key events, Google Ads conversion actions, Meta action types, Business Profile actions — suggests
 * what each one means (ConversionGoalTypes) and whether it counts in the brand total, and sums the
 * counted ones for a period. Defaults avoid double counting: the website is measured by GA4, so Ads
 * website/GA4-import actions and Meta pixel events are listed but not counted while GA4 key events are
 * counted. Operator choices are never overwritten.
 */
final class BrandConversionDictionary
{
    /** Business Profile metrics that are customer actions: metric => [label, type, counts]. */
    private const array GBP_METRICS = [
        'CALL_CLICKS' => ['Haritalar: arama', ConversionGoalTypes::PHONE_CALL, true],
        'BUSINESS_CONVERSATIONS' => ['Haritalar: mesaj', ConversionGoalTypes::CUSTOM, true],
        'BUSINESS_BOOKINGS' => ['Haritalar: rezervasyon', ConversionGoalTypes::BOOKING, true],
        'BUSINESS_DIRECTION_REQUESTS' => ['Haritalar: yol tarifi', ConversionGoalTypes::CUSTOM, false],
        'WEBSITE_CLICKS' => ['Haritalar: web sitesi tıklaması', ConversionGoalTypes::CUSTOM, false],
    ];

    /** GA4 events that are engagement, not conversions, even when marked as key events. */
    private const array GA4_ENGAGEMENT = ['page_view', 'scroll', 'session_start', 'first_visit', 'user_engagement', 'click', 'view_search_results', 'video_start', 'video_progress', 'file_download'];

    /**
     * @return array{found: int, created: int}
     */
    public function discover(Brand $brand): array
    {
        $scope = BrandMeasurementScope::for($brand);
        if ($scope->isEmpty()) {
            return ['found' => 0, 'created' => 0];
        }
        $existing = BrandConversionSource::query()->where('brand_id', $brand->id)->get()
            ->keyBy(fn (BrandConversionSource $row): string => $row->source.'|'.$row->source_key);
        $stats = ['found' => 0, 'created' => 0];
        $save = function (string $source, string $key, string $label, string $type, bool $counts, array $metadata) use ($brand, $existing, &$stats): void {
            $key = mb_substr(trim($key), 0, 255);
            if ($key === '') {
                return;
            }
            $stats['found']++;
            $row = $existing->get($source.'|'.$key);
            if ($row === null) {
                $row = BrandConversionSource::query()->create([
                    'brand_id' => $brand->id, 'source' => $source, 'source_key' => $key, 'label' => mb_substr($label, 0, 255),
                    'conversion_type' => $type, 'counts' => $counts, 'origin' => BrandConversionSource::ORIGIN_AUTO,
                    'metadata' => $metadata, 'last_seen_at' => now(),
                ]);
                $existing->put($source.'|'.$key, $row);
                $stats['created']++;

                return;
            }
            $attributes = ['label' => mb_substr($label, 0, 255), 'metadata' => $metadata, 'last_seen_at' => now()];
            if ($row->origin !== BrandConversionSource::ORIGIN_OPERATOR) {
                $attributes += ['conversion_type' => $type, 'counts' => $counts];
            }
            $row->fill($attributes)->save();
        };

        $ga4Events = $this->ga4Events($scope);
        foreach ($ga4Events as $event) {
            $engagement = in_array($event, self::GA4_ENGAGEMENT, true);
            $save(BrandConversionSource::SOURCE_GA4, $event, $event, $this->guessType($event), ! $engagement, []);
        }
        $ga4Counted = BrandConversionSource::query()->where('brand_id', $brand->id)
            ->where('source', BrandConversionSource::SOURCE_GA4)->where('counts', true)->exists();

        foreach ($this->adsActions($scope) as $action) {
            [$type, $counts, $reason] = $this->adsSuggestion($action, $ga4Counted);
            $save(BrandConversionSource::SOURCE_GOOGLE_ADS, $action['id'], $action['name'], $type, $counts, [
                'customer_id' => $action['customer_id'], 'category' => $action['category'], 'type' => $action['type'],
                'status' => $action['status'], 'primary' => $action['primary'], 'ga4_event' => $action['ga4_event'], 'note' => $reason,
            ]);
        }

        foreach ($this->metaActions($scope) as $actionType) {
            $suggestion = $this->metaSuggestion($actionType);
            if ($suggestion !== null) {
                $save(BrandConversionSource::SOURCE_META, $actionType, $suggestion[0], $suggestion[1], $suggestion[2], []);
            }
        }

        foreach ($this->gbpMetrics($scope) as $metric) {
            [$label, $type, $counts] = self::GBP_METRICS[$metric];
            $save(BrandConversionSource::SOURCE_GBP, $metric, $label, $type, $counts, []);
        }

        return $stats;
    }

    /** Operator choice for one signal; kept by later discovery runs. */
    public function set(BrandConversionSource $row, string $conversionType, bool $counts): void
    {
        if (! array_key_exists($conversionType, ConversionGoalTypes::options())) {
            throw new \InvalidArgumentException('Unknown conversion type: '.$conversionType);
        }
        $row->forceFill(['conversion_type' => $conversionType, 'counts' => $counts, 'origin' => BrandConversionSource::ORIGIN_OPERATOR])->save();
    }

    /**
     * Counted conversions of the brand in [from, to] (dates inclusive), per signal, type and source.
     *
     * @return array{total: float, by_type: array<string, float>, by_source: array<string, float>, by_row: array<int, float>}
     */
    public function totals(Brand $brand, CarbonInterface $from, CarbonInterface $to, bool $countedOnly = true): array
    {
        $rows = BrandConversionSource::query()->where('brand_id', $brand->id)
            ->when($countedOnly, fn ($query) => $query->where('counts', true))->get();
        $result = ['total' => 0.0, 'by_type' => [], 'by_source' => [], 'by_row' => []];
        if ($rows->isEmpty()) {
            return $result;
        }
        $scope = BrandMeasurementScope::for($brand);
        $values = $this->values($scope, $rows->groupBy('source')->map(fn ($group) => $group->pluck('source_key')->all())->all(), $from->toDateString(), $to->toDateString());

        foreach ($rows as $row) {
            $value = round((float) ($values[$row->source][$row->source_key] ?? 0), 2);
            $result['by_row'][$row->id] = $value;
            if (! $row->counts) {
                continue;
            }
            $result['total'] += $value;
            $result['by_type'][$row->conversion_type] = ($result['by_type'][$row->conversion_type] ?? 0) + $value;
            $result['by_source'][$row->source] = ($result['by_source'][$row->source] ?? 0) + $value;
        }
        $result['total'] = round($result['total'], 2);
        arsort($result['by_type']);

        return $result;
    }

    /**
     * Last 30 days against the 30 days before (data lands with a delay, so the window ends yesterday).
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, change_pct: ?float, from: string, to: string}
     */
    public function summary(Brand $brand, int $days = 30): array
    {
        $to = now()->subDay()->startOfDay();
        $from = $to->copy()->subDays($days - 1);
        $current = $this->totals($brand, $from, $to, false);
        $previous = $this->totals($brand, $from->copy()->subDays($days), $from->copy()->subDay());

        return [
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $previous['total'] > 0 ? round(($current['total'] - $previous['total']) / $previous['total'] * 100, 1) : null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @param  array<string, list<string>>  $keysBySource
     * @return array<string, array<string, float>>
     */
    private function values(BrandMeasurementScope $scope, array $keysBySource, string $from, string $to): array
    {
        $out = [];
        $sum = function (string $table, string $keyColumn, string $valueColumn, array $keys, ?callable $extra = null) use ($scope, $from, $to): array {
            if ($keys === [] || ! Schema::hasTable($table)) {
                return [];
            }
            $query = DB::table($table)->whereBetween('reporting_date', [$from, $to])->whereIn($keyColumn, $keys);
            $scope->apply($query);
            if ($extra !== null) {
                $extra($query);
            }

            $grammar = DB::getQueryGrammar(); // GA4 columns are camelCase: quote them for Postgres

            return $query->groupBy($keyColumn)->selectRaw($grammar->wrap($keyColumn).' as k, sum('.$grammar->wrap($valueColumn).') as v')->pluck('v', 'k')
                ->map(fn ($v): float => (float) $v)->all();
        };

        $out[BrandConversionSource::SOURCE_GA4] = $sum('ga4_key_event_daily', 'eventName', 'keyEvents', $keysBySource[BrandConversionSource::SOURCE_GA4] ?? []);
        $out[BrandConversionSource::SOURCE_GOOGLE_ADS] = $sum('google_ads_conversion_action_daily', 'conversion_action_id', 'conversions', $keysBySource[BrandConversionSource::SOURCE_GOOGLE_ADS] ?? []);
        $level = $this->metaLevel($scope);
        $out[BrandConversionSource::SOURCE_META] = $level === null ? [] : $sum('meta_typed_action_daily', 'action_type', 'action_value', $keysBySource[BrandConversionSource::SOURCE_META] ?? [], fn ($q) => $q->where('entity_level', $level));
        $out[BrandConversionSource::SOURCE_GBP] = $sum('gbp_performance_daily', 'metric', 'value', $keysBySource[BrandConversionSource::SOURCE_GBP] ?? []);

        return $out;
    }

    /** @return list<string> */
    private function ga4Events(BrandMeasurementScope $scope): array
    {
        $events = [];
        if (Schema::hasTable('ga4_property_metadata')) {
            foreach ($scope->apply(DB::table('ga4_property_metadata'))->pluck('metadata') as $metadata) {
                foreach ((array) data_get(json_decode((string) $metadata, true), 'key_events', []) as $keyEvent) {
                    $name = is_array($keyEvent) ? ($keyEvent['eventName'] ?? $keyEvent['event_name'] ?? null) : $keyEvent;
                    if (is_string($name) && $name !== '') {
                        $events[$name] = true;
                    }
                }
            }
        }
        if (Schema::hasTable('ga4_key_event_daily')) {
            foreach ($scope->apply(DB::table('ga4_key_event_daily'))->where('reporting_date', '>=', now()->subDays(90)->toDateString())->distinct()->pluck('eventName') as $name) {
                $events[(string) $name] = true;
            }
        }

        return array_keys($events);
    }

    /** @return list<array{id: string, customer_id: string, name: string, category: string, type: string, status: string, primary: bool, ga4_event: ?string}> */
    private function adsActions(BrandMeasurementScope $scope): array
    {
        if (! Schema::hasTable('google_ads_conversion_action_snapshot')) {
            return [];
        }
        $actions = [];
        foreach ($scope->apply(DB::table('google_ads_conversion_action_snapshot'))->orderBy('id')->get(['customer_id', 'conversion_action_id', 'metadata']) as $row) {
            $meta = (array) json_decode((string) $row->metadata, true);
            $status = strtoupper((string) ($meta['status'] ?? 'UNKNOWN'));
            if ($status === 'REMOVED') {
                continue;
            }
            $actions[(string) $row->conversion_action_id] = [
                'id' => (string) $row->conversion_action_id,
                'customer_id' => (string) $row->customer_id,
                'name' => (string) ($meta['name'] ?? 'Dönüşüm '.$row->conversion_action_id),
                'category' => strtoupper((string) ($meta['category'] ?? 'UNKNOWN')),
                'type' => strtoupper((string) ($meta['type'] ?? 'UNKNOWN')),
                'status' => $status,
                'primary' => (bool) ($meta['primary_for_goal'] ?? $meta['primaryForGoal'] ?? false),
                'ga4_event' => data_get($meta, 'ga4_event_name') ?? data_get($meta, 'google_analytics_4_settings.event_name') ?? data_get($meta, 'googleAnalytics4Settings.eventName'),
            ];
        }

        return array_values($actions);
    }

    /**
     * @param  array{id: string, name: string, category: string, type: string, status: string, primary: bool, ga4_event: ?string}  $action
     * @return array{0: string, 1: bool, 2: string}
     */
    private function adsSuggestion(array $action, bool $ga4Counted): array
    {
        $type = match ($action['category']) {
            'PHONE_CALL_LEAD' => ConversionGoalTypes::PHONE_CALL,
            'SUBMIT_LEAD_FORM', 'CONTACT', 'REQUEST_QUOTE', 'SIGNUP' => ConversionGoalTypes::FORM_SUBMISSION,
            'BOOK_APPOINTMENT' => ConversionGoalTypes::APPOINTMENT_REQUEST,
            'PURCHASE' => ConversionGoalTypes::PURCHASE,
            'QUALIFIED_LEAD', 'CONVERTED_LEAD' => ConversionGoalTypes::QUALIFIED_LEAD,
            default => $this->guessType($action['name']),
        };
        if ($action['status'] !== 'ENABLED' || ! $action['primary']) {
            return [$type, false, 'secondary_or_disabled'];
        }
        if (str_starts_with($action['type'], 'GOOGLE_ANALYTICS') || $action['ga4_event'] !== null) {
            return [$type, false, 'imported_from_ga4'];
        }
        if (in_array($action['type'], ['AD_CALL', 'CLICK_TO_CALL', 'GOOGLE_HOSTED', 'LEAD_FORM_SUBMIT', 'STORE_VISITS'], true)
            || str_contains($action['type'], 'CALL') || str_contains($action['type'], 'LEAD_FORM')) {
            return [$type, true, 'ads_only_signal'];
        }

        // Website tag actions measure the same visits GA4 measures.
        return $ga4Counted ? [$type, false, 'website_measured_by_ga4'] : [$type, true, 'no_ga4'];
    }

    /** @return list<string> */
    private function metaActions(BrandMeasurementScope $scope): array
    {
        $level = $this->metaLevel($scope);
        if ($level === null) {
            return [];
        }

        return $scope->apply(DB::table('meta_typed_action_daily'))->where('entity_level', $level)
            ->where('reporting_date', '>=', now()->subDays(90)->toDateString())->distinct()->pluck('action_type')
            ->map(fn ($type): string => (string) $type)->all();
    }

    /** One entity level per brand so the same action is not summed at account, campaign and ad level. */
    private function metaLevel(BrandMeasurementScope $scope): ?string
    {
        if (! Schema::hasTable('meta_typed_action_daily')) {
            return null;
        }
        $levels = $scope->apply(DB::table('meta_typed_action_daily'))->distinct()->pluck('entity_level')->all();
        foreach (['account', 'campaign', 'adset', 'ad'] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: bool}|null  label, type, counts — null for non-conversion actions */
    private function metaSuggestion(string $actionType): ?array
    {
        $pixel = str_starts_with($actionType, 'offsite_conversion.');
        $prefix = $pixel ? 'Meta piksel: ' : 'Meta: ';

        $suggestion = match (true) {
            str_contains($actionType, 'messaging_conversation_started') => ['WhatsApp / mesaj başlatma', ConversionGoalTypes::WHATSAPP_CONVERSATION],
            $actionType === 'lead' || str_contains($actionType, 'lead_grouped') || str_ends_with($actionType, 'fb_pixel_lead') => ['form / potansiyel müşteri', ConversionGoalTypes::FORM_SUBMISSION],
            str_contains($actionType, 'schedule') => ['randevu', ConversionGoalTypes::APPOINTMENT_REQUEST],
            in_array($actionType, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'], true) => ['satın alma', ConversionGoalTypes::PURCHASE],
            str_contains($actionType, 'click_to_call') => ['arama', ConversionGoalTypes::PHONE_CALL],
            str_contains($actionType, 'contact') => ['iletişim', ConversionGoalTypes::FORM_SUBMISSION],
            default => null,
        };
        if ($suggestion === null) {
            return null;
        }

        // Pixel events happen on the website, which GA4 already counts; "lead", "purchase", "omni_*" and "*_total"
        // are Meta aggregates that include pixel events, so only on-Meta actions are counted by default.
        $aggregate = in_array($actionType, ['lead', 'purchase'], true) || str_starts_with($actionType, 'omni_') || str_ends_with($actionType, '_total');

        return [$prefix.$suggestion[0], $suggestion[1], ! $pixel && ! $aggregate];
    }

    /** @return list<string> */
    private function gbpMetrics(BrandMeasurementScope $scope): array
    {
        if (! Schema::hasTable('gbp_performance_daily')) {
            return [];
        }

        return $scope->apply(DB::table('gbp_performance_daily'))->whereIn('metric', array_keys(self::GBP_METRICS))
            ->where('reporting_date', '>=', now()->subDays(90)->toDateString())->distinct()->pluck('metric')
            ->map(fn ($metric): string => (string) $metric)->all();
    }

    public function guessType(string $name): string
    {
        $folded = ' '.str_replace(['_', '-', '.'], ' ', SeoText::fold($name)).' ';

        return match (true) {
            (bool) preg_match('/whatsapp|wa me/', $folded) => ConversionGoalTypes::WHATSAPP_CONVERSATION,
            (bool) preg_match('/\b(call|phone|tel|telefon|arama|ara)\b|click to call/', $folded) => ConversionGoalTypes::PHONE_CALL,
            (bool) preg_match('/randevu|appointment|schedule/', $folded) => ConversionGoalTypes::APPOINTMENT_REQUEST,
            (bool) preg_match('/\bbook|rezervasyon/', $folded) => ConversionGoalTypes::BOOKING,
            (bool) preg_match('/purchase|satin|siparis|checkout/', $folded) => ConversionGoalTypes::PURCHASE,
            (bool) preg_match('/qualified|nitelikli/', $folded) => ConversionGoalTypes::QUALIFIED_LEAD,
            (bool) preg_match('/lead|form|submit|contact|iletisim|teklif|basvuru|generate lead/', $folded) => ConversionGoalTypes::FORM_SUBMISSION,
            default => ConversionGoalTypes::CUSTOM,
        };
    }
}
