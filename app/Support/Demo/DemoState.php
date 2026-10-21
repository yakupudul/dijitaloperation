<?php

namespace App\Support\Demo;

use App\Services\Operator\AgencySettingService;
use App\Support\Options\CountryOptions;

/**
 * Session helper for operator UI chrome (flash, filters, settings overrides).
 *
 * Does NOT seed Atlas / DemoCatalog business truth. Reading an empty session
 * must not create customers, brands, assets, findings, or team members.
 */
final class DemoState
{
    public const string SESSION_KEY = 'moxdop.demo_state';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $state = session()->get(self::SESSION_KEY);
        if (! is_array($state)) {
            return self::defaults();
        }

        $defaults = self::defaults();
        foreach (array_keys($defaults) as $key) {
            if (! array_key_exists($key, $state)) {
                $state[$key] = $defaults[$key];
            }
        }

        return $state;
    }

    public static function reset(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Empty operational session — never Atlas Health Group / DemoCatalog seeds.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'customers' => [],
            'brands' => [],
            'contacts' => [],
            'customer_activity' => [],
            'recommendations' => [],
            'tasks' => [],
            'activity' => [],
            'demo_assets' => [],
            'import' => [
                'running' => false,
                'started_at' => null,
                'overall_ready' => 0,
                'overall_total' => 0,
                'tick' => 0,
                'selected_account_id' => null,
            ],
            'public_research' => [
                'running' => false,
                'completed' => false,
                'steps' => [],
            ],
            'discovery_candidates' => [],
            'discovery_history' => [],
            'discovery_conflict_resolutions' => [],
            'connector_bindings' => [],
            'finding_statuses' => [],
            'settings_overrides' => [],
            'brand_business_context' => [],
            'activity_events' => [],
            'opportunity_statuses' => [],
            'business_outcome_overrides' => [],
            'capture_notes' => [],
            'report_config' => [],
            'demo_notifications' => [],
            'execution_overrides' => [],
            'hypotheses' => [],
            'ai_brief_visible' => false,
            'period_preset' => app(AgencySettingService::class)->defaultAnalyticalDateRange(),
            'period_start' => null,
            'period_end' => null,
            'compare' => true,
            'filters' => [
                'meta_status' => null,
                'meta_objective' => null,
                'meta_adset_status' => null,
                'meta_ad_status' => null,
                'meta_breakdown_dimension' => null,
                'gads_classification' => null,
                'finding_severity' => null,
                'finding_asset_type' => null,
                'task_status' => null,
                'gbp_keyword' => null,
                'website_issue_severity' => null,
            ],
            'flash' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function put(array $patch): void
    {
        $state = array_replace_recursive(self::all(), $patch);
        session()->put(self::SESSION_KEY, $state);
    }

    public static function flash(string $message, string $tone = 'success'): void
    {
        self::put(['flash' => ['message' => $message, 'tone' => $tone]]);
    }

    public static function pullFlash(): ?array
    {
        $state = self::all();
        $flash = $state['flash'] ?? null;
        if ($flash !== null) {
            $state['flash'] = null;
            session()->put(self::SESSION_KEY, $state);
        }

        return is_array($flash) ? $flash : null;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public static function updateCustomer(string $id, array $customer): void
    {
        $state = self::all();
        foreach ($state['customers'] as &$row) {
            if (($row['id'] ?? '') !== $id) {
                continue;
            }
            $row = self::normalizeCustomer(array_merge($row, $customer, ['id' => $id]));
        }
        unset($row);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Customer changes saved.');
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public static function normalizeCustomer(array $customer): array
    {
        $hqCountry = $customer['hq_country'] ?? null;
        $hqCity = $customer['hq_city'] ?? null;
        $services = $customer['services'] ?? [];
        if (! is_array($services)) {
            $services = [];
        }

        $customer['type'] = $customer['type'] ?? 'company';
        $customer['status'] = $customer['status'] ?? 'active';
        $customer['services'] = array_values($services);
        $customer['responsible_user_ids'] = array_values($customer['responsible_user_ids'] ?? []);
        $customer['hq'] = CountryOptions::formatHq(
            is_string($hqCity) ? $hqCity : null,
            is_string($hqCountry) ? $hqCountry : null
        );
        $customer['brands_count'] = $customer['brands_count'] ?? 0;
        $customer['digital_assets_count'] = $customer['digital_assets_count'] ?? 0;
        $customer['open_findings'] = $customer['open_findings'] ?? ($customer['open_issues'] ?? 0);
        $customer['open_issues'] = $customer['open_findings'];
        $customer['open_tasks'] = $customer['open_tasks'] ?? 0;
        $customer['overdue_tasks'] = $customer['overdue_tasks'] ?? 0;

        return $customer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBrand(string $id): ?array
    {
        $brand = collect(self::all()['brands'] ?? [])->firstWhere('id', $id);

        return is_array($brand) ? self::normalizeBrand($brand) : null;
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public static function normalizeBrand(array $brand): array
    {
        $country = $brand['primary_country'] ?? null;
        $city = $brand['hq_city'] ?? null;
        $brand['sector'] = $brand['sector'] ?? ($brand['industry'] ?? null);
        $brand['industry'] = $brand['sector'];
        $brand['target_markets'] = array_values($brand['target_markets'] ?? []);
        $brand['languages'] = array_values($brand['languages'] ?? []);
        $brand['responsible_user_ids'] = array_values($brand['responsible_user_ids'] ?? []);
        $brand['location'] = CountryOptions::formatHq(
            is_string($city) ? $city : null,
            is_string($country) ? $country : null
        );
        if (($brand['location'] ?? '—') === '—' && is_string($country) && $country !== '') {
            $brand['location'] = CountryOptions::label($country);
        }
        $brand['open_findings'] = $brand['open_findings'] ?? 0;
        $brand['open_tasks'] = $brand['open_tasks'] ?? 0;
        $brand['assets_count'] = $brand['assets_count'] ?? 0;

        return $brand;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function connectorBindings(string $connector): array
    {
        $all = self::all()['connector_bindings'] ?? [];

        return is_array($all[$connector] ?? null) ? $all[$connector] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settingsOverrides(): array
    {
        $overrides = self::all()['settings_overrides'] ?? [];

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function brandBusinessContext(string $brandId): ?array
    {
        $store = self::all()['brand_business_context'] ?? [];
        if (! is_array($store) || ! isset($store[$brandId]) || ! is_array($store[$brandId])) {
            return null;
        }

        return $store[$brandId];
    }

    /**
     * @param  array{
     *     title: string,
     *     scope?: string,
     *     detail?: string,
     *     actor?: string,
     *     actor_kind?: string,
     *     status?: string,
     *     asset_type?: ?string,
     *     route?: ?string
     * }  $event
     */
    public static function recordActivityEvent(array $event): void
    {
        $state = self::all();
        $events = is_array($state['activity_events'] ?? null) ? $state['activity_events'] : [];
        array_unshift($events, [
            'id' => 'act-live-'.substr(md5(($event['title'] ?? '').microtime(true)), 0, 10),
            'time' => now()->timezone(config('app.timezone'))->format('H:i'),
            'when' => 'Today',
            'title' => $event['title'],
            'scope' => $event['scope'] ?? 'MoxDOP',
            'detail' => $event['detail'] ?? '',
            'actor' => $event['actor'] ?? 'System',
            'actor_kind' => $event['actor_kind'] ?? 'system',
            'status' => $event['status'] ?? 'success',
            'asset_type' => $event['asset_type'] ?? null,
            'route' => $event['route'] ?? null,
            'occurred_at' => now()->toIso8601String(),
        ]);
        $state['activity_events'] = array_slice($events, 0, 100);
        session()->put(self::SESSION_KEY, $state);
    }

    public static function setPeriod(string $preset, ?string $start = null, ?string $end = null): void
    {
        $state = self::all();
        $compare = array_key_exists('compare', $state) ? (bool) $state['compare'] : true;

        self::put([
            'period_preset' => $preset,
            'period_start' => $start,
            'period_end' => $end,
            'compare' => $compare,
        ]);
    }

    /**
     * Captured notes/decisions from Global Capture (session only).
     *
     * @return list<array<string, mixed>>
     */
    public static function captureNotes(): array
    {
        $notes = self::all()['capture_notes'] ?? [];

        return is_array($notes) ? array_values($notes) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function demoNotifications(): array
    {
        $items = self::all()['demo_notifications'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }
}
