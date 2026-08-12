<?php

namespace App\Support\Demo;

use App\Support\Options\CountryOptions;

/**
 * Session-backed mutable Demo Mode state. Isolated from the operator database.
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
            $state = self::defaults();
            session()->put(self::SESSION_KEY, $state);

            return $state;
        }

        $defaults = self::defaults();
        foreach (['contacts', 'customer_activity', 'demo_assets', 'discovery_candidates'] as $key) {
            if (! array_key_exists($key, $state)) {
                $state[$key] = $defaults[$key] ?? [];
            }
        }

        return $state;
    }

    public static function reset(): void
    {
        session()->put(self::SESSION_KEY, self::defaults());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'customers' => [DemoCatalog::customer()],
            'brands' => [DemoCatalog::brand()],
            'contacts' => DemoCatalog::customerContacts(),
            'customer_activity' => DemoCatalog::customerActivity(),
            'recommendations' => DemoCatalog::recommendationsSeed(),
            'tasks' => DemoCatalog::tasksSeed(),
            'activity' => DemoCatalog::activitySeed(),
            'demo_assets' => [],
            'import' => [
                'running' => false,
                'started_at' => null,
                'overall_ready' => 11,
                'overall_total' => 31,
                'tick' => 0,
                'selected_account_id' => null,
            ],
            'public_research' => [
                'running' => false,
                'completed' => false,
                'steps' => [],
            ],
            'discovery_candidates' => DemoCatalog::brandDiscoveryCandidates(),
            'ai_brief_visible' => false,
            'period_preset' => 'last_28',
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

    public static function setFilter(string $key, mixed $value): void
    {
        $state = self::all();
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : self::defaults()['filters'];
        $filters[$key] = $value;
        $state['filters'] = $filters;
        session()->put(self::SESSION_KEY, $state);
    }

    public static function getFilter(string $key, mixed $default = null): mixed
    {
        $filters = self::all()['filters'] ?? [];

        if (! is_array($filters) || ! array_key_exists($key, $filters)) {
            return $default;
        }

        return $filters[$key] ?? $default;
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
    public static function addCustomer(array $customer): void
    {
        $state = self::all();
        $state['customers'][] = self::normalizeCustomer($customer);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Customer “'.$customer['name'].'” saved (Demo Mode).');
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
        self::flash('Customer changes saved (Demo Mode).');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findCustomer(string $id): ?array
    {
        $customer = collect(self::all()['customers'] ?? [])->firstWhere('id', $id);

        return is_array($customer) ? self::normalizeCustomer($customer) : null;
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

    public static function setCustomerStatus(string $id, string $status): void
    {
        self::updateCustomer($id, ['status' => $status]);
        self::flash($status === 'archived' ? 'Customer archived (Demo Mode).' : 'Customer restored (Demo Mode).');
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    public static function addBrand(array $brand): void
    {
        $state = self::all();
        $state['brands'][] = self::normalizeBrand($brand);
        $customerId = $brand['customer_id'] ?? null;
        if (is_string($customerId)) {
            foreach ($state['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $customer['brands_count'] = (int) ($customer['brands_count'] ?? 0) + 1;
                }
            }
            unset($customer);
        }
        session()->put(self::SESSION_KEY, $state);
        self::flash('Brand “'.$brand['name'].'” added (Demo Mode).');
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    public static function updateBrand(string $id, array $brand): void
    {
        $state = self::all();
        foreach ($state['brands'] as &$row) {
            if (($row['id'] ?? '') !== $id) {
                continue;
            }
            $row = self::normalizeBrand(array_merge($row, $brand, ['id' => $id]));
        }
        unset($row);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Brand changes saved (Demo Mode).');
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
     * @param  array<string, mixed>  $contact
     */
    public static function addContact(array $contact): void
    {
        $state = self::all();
        $state['contacts'] = $state['contacts'] ?? [];
        $state['contacts'][] = $contact;
        session()->put(self::SESSION_KEY, $state);
        self::flash('Contact “'.$contact['name'].'” saved (Demo Mode).');
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    public static function updateContact(string $id, array $contact): void
    {
        $state = self::all();
        foreach ($state['contacts'] as &$row) {
            if (($row['id'] ?? '') !== $id) {
                continue;
            }
            $row = array_merge($row, $contact, ['id' => $id]);
        }
        unset($row);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Contact updated (Demo Mode).');
    }

    public static function deleteContact(string $id): void
    {
        $state = self::all();
        $state['contacts'] = array_values(array_filter(
            $state['contacts'] ?? [],
            static fn (array $row): bool => ($row['id'] ?? '') !== $id
        ));
        session()->put(self::SESSION_KEY, $state);
        self::flash('Contact removed (Demo Mode).');
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    public static function addDemoAsset(array $asset): void
    {
        $state = self::all();
        $state['demo_assets'] = $state['demo_assets'] ?? [];
        $state['demo_assets'][] = $asset;
        $brandId = $asset['brand_id'] ?? null;
        if (is_string($brandId)) {
            foreach ($state['brands'] as &$brand) {
                if (($brand['id'] ?? '') === $brandId) {
                    $brand['assets_count'] = (int) ($brand['assets_count'] ?? 0) + 1;
                }
            }
            unset($brand);
            $customerId = collect($state['brands'])->firstWhere('id', $brandId)['customer_id'] ?? null;
            if (is_string($customerId)) {
                foreach ($state['customers'] as &$customer) {
                    if (($customer['id'] ?? '') === $customerId) {
                        $customer['digital_assets_count'] = (int) ($customer['digital_assets_count'] ?? 0) + 1;
                    }
                }
                unset($customer);
            }
        }
        session()->put(self::SESSION_KEY, $state);
        self::flash('Digital Asset “'.$asset['name'].'” added (Demo Mode).');
    }

    public static function setRecommendationStatus(string $id, string $status): void
    {
        $state = self::all();
        foreach ($state['recommendations'] as &$row) {
            if ($row['id'] === $id) {
                $row['status'] = $status;
            }
        }
        unset($row);
        session()->put(self::SESSION_KEY, $state);
    }

    public static function createTaskFromRecommendation(string $recommendationId): void
    {
        $state = self::all();
        $rec = collect($state['recommendations'])->firstWhere('id', $recommendationId);
        if ($rec === null) {
            return;
        }

        $taskId = 't-demo-'.substr(md5($recommendationId.microtime()), 0, 8);
        $state['tasks'][] = [
            'id' => $taskId,
            'title' => $rec['title'],
            'brand' => $rec['brand'],
            'asset' => $rec['asset'],
            'recommendation_id' => $recommendationId,
            'owner' => 'Unassigned',
            'priority' => 'medium',
            'due' => 'Next week',
            'status' => 'open',
            'description' => $rec['action'],
            'success_signal' => $rec['success'],
            'why' => [
                'finding' => $rec['observation'],
                'recommendation' => $rec['title'],
                'evidence' => $rec['evidence'],
            ],
            'outcome' => null,
        ];
        foreach ($state['recommendations'] as &$row) {
            if ($row['id'] === $recommendationId) {
                $row['status'] = 'approved';
            }
        }
        unset($row);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Task created from recommendation (Demo Mode).');
    }

    public static function setTaskStatus(string $id, string $status): void
    {
        $state = self::all();
        foreach ($state['tasks'] as &$task) {
            if ($task['id'] !== $id) {
                continue;
            }
            $task['status'] = $status;
            if ($status === 'completed' && empty($task['outcome'])) {
                $task['outcome'] = [
                    'status' => 'associated_improvement',
                    'label' => 'Associated improvement observed',
                    'before' => 'CPL ₺691',
                    'after' => 'CPL ₺548',
                    'period' => '14 days',
                    'note' => 'Not claiming causality — follow-up data only.',
                ];
            }
        }
        unset($task);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Task updated (Demo Mode).');
    }

    public static function startMetaImport(): void
    {
        $state = self::all();
        $state['import']['running'] = true;
        $state['import']['started_at'] = now()->toIso8601String();
        $state['import']['tick'] = 0;
        $state['import']['overall_ready'] = 11;
        array_unshift($state['activity'], [
            'id' => 'a-import-'.time(),
            'title' => 'Meta data import',
            'status' => 'running',
            'detail' => '11 / 31 accounts ready',
            'when' => 'Now',
        ]);
        session()->put(self::SESSION_KEY, $state);
        self::flash('Meta data import started (Demo Mode simulation).', 'info');
    }

    public static function tickMetaImport(): void
    {
        $state = self::all();
        if (! ($state['import']['running'] ?? false)) {
            return;
        }
        $state['import']['tick'] = (int) $state['import']['tick'] + 1;
        if ($state['import']['tick'] % 2 === 0 && $state['import']['overall_ready'] < 30) {
            $state['import']['overall_ready']++;
        }
        if ($state['import']['overall_ready'] >= 30) {
            $state['import']['running'] = false;
            $state['import']['overall_ready'] = 30;
            if (isset($state['activity'][0])) {
                $state['activity'][0]['status'] = 'partial';
                $state['activity'][0]['detail'] = '30 / 31 accounts ready · 1 needs attention';
            }
        }
        session()->put(self::SESSION_KEY, $state);
    }

    public static function startPublicResearch(): void
    {
        $steps = array_map(
            static fn (array $row): string => (string) $row['step'],
            DemoCatalog::publicDiscoverySteps()
        );
        self::put([
            'public_research' => [
                'running' => false,
                'completed' => true,
                'steps' => $steps,
                'cards' => DemoCatalog::publicDiscoverySteps(),
                'website' => 'atlasdental.example',
                'pages_inspected' => 8,
                'completed_at' => 'Today at 11:42',
            ],
            'discovery_candidates' => DemoCatalog::brandDiscoveryCandidates(),
        ]);
        self::flash('Public brand research completed (Demo Mode · PUBLIC DISCOVERY provenance).');
    }

    public static function setDiscoveryCandidateStatus(string $id, string $status): void
    {
        $state = self::all();
        $candidates = $state['discovery_candidates'] ?? DemoCatalog::brandDiscoveryCandidates();
        foreach ($candidates as &$row) {
            if (($row['id'] ?? '') === $id) {
                $row['status'] = $status;
            }
        }
        unset($row);
        $state['discovery_candidates'] = $candidates;
        session()->put(self::SESSION_KEY, $state);

        $label = match ($status) {
            'accepted' => 'accepted into Business Context review queue',
            'ignored' => 'ignored',
            'edited' => 'edited & accepted for review',
            default => 'updated',
        };
        self::flash('Discovery candidate '.$label.' (Demo Mode — does not silently overwrite Business Context).');
    }

    public static function showAiBrief(): void
    {
        self::put(['ai_brief_visible' => true]);
        self::flash('Brand analysis ready (Demo Mode — no live AI call).', 'info');
    }

    /**
     * Queue / surface a recommendation from an AI priority line (demo session only).
     */
    public static function createRecommendationFromAiPriority(string $priority, ?string $recommendationId = null): void
    {
        $state = self::all();
        $existing = null;

        if ($recommendationId !== null) {
            $existing = collect($state['recommendations'])->firstWhere('id', $recommendationId);
        }

        if ($existing !== null) {
            foreach ($state['recommendations'] as &$row) {
                if ($row['id'] === $recommendationId) {
                    $row['status'] = 'pending';
                }
            }
            unset($row);
            session()->put(self::SESSION_KEY, $state);
            self::flash('Recommendation “'.$existing['title'].'” ready in queue (Demo Mode).');

            return;
        }

        $id = 'r-ai-'.substr(md5($priority.microtime()), 0, 8);
        $state['recommendations'][] = [
            'id' => $id,
            'finding_id' => null,
            'title' => $priority,
            'observation' => 'Created from brand AI analysis priority.',
            'why' => 'Operator accepted an AI-suggested priority for follow-up.',
            'evidence' => 'Brand AI analysis (demo — no live model call)',
            'action' => $priority,
            'dependencies' => 'Confirm with channel owner.',
            'success' => 'Priority addressed or consciously deferred.',
            'failure' => 'No follow-up within 14 days.',
            'watch' => [],
            'status' => 'pending',
            'brand' => DemoCatalog::brand()['name'],
            'asset' => 'Cross-channel',
        ];
        session()->put(self::SESSION_KEY, $state);
        self::flash('Recommendation created from AI analysis (Demo Mode).');
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
}
