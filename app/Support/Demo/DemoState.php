<?php

namespace App\Support\Demo;

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
            'recommendations' => DemoCatalog::recommendationsSeed(),
            'tasks' => DemoCatalog::tasksSeed(),
            'activity' => DemoCatalog::activitySeed(),
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
            'ai_brief_visible' => false,
            'period_preset' => 'last_28',
            'period_start' => null,
            'period_end' => null,
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
    public static function addCustomer(array $customer): void
    {
        $state = self::all();
        $state['customers'][] = $customer;
        session()->put(self::SESSION_KEY, $state);
        self::flash('Customer “'.$customer['name'].'” added (Demo Mode).');
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    public static function addBrand(array $brand): void
    {
        $state = self::all();
        $state['brands'][] = $brand;
        session()->put(self::SESSION_KEY, $state);
        self::flash('Brand “'.$brand['name'].'” added (Demo Mode).');
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
        $steps = [
            'Website analyzed',
            'Search presence discovered',
            'Google Maps listing found',
            'Review sources found',
            'Competitors identified',
            'Domain / SSL information found',
            'Public social references found',
        ];
        self::put([
            'public_research' => [
                'running' => false,
                'completed' => true,
                'steps' => $steps,
            ],
        ]);
        self::flash('Public brand research completed (Demo Mode · PUBLIC DISCOVERY provenance).');
    }

    public static function showAiBrief(): void
    {
        self::put(['ai_brief_visible' => true]);
        self::flash('Brand analysis ready (Demo Mode — no live AI call).', 'info');
    }

    public static function setPeriod(string $preset, ?string $start = null, ?string $end = null): void
    {
        self::put([
            'period_preset' => $preset,
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }
}
