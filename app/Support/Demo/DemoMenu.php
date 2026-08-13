<?php

namespace App\Support\Demo;

/**
 * Canonical /app navigation for the full product demo (TailAdmin sidebar).
 *
 * Module/package architecture is intentionally not exposed here.
 */
final class DemoMenu
{
    /**
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'label' => 'Menu',
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'demo.dashboard', 'icon' => 'dashboard'],
                ],
            ],
            [
                'label' => 'Portfolio',
                'items' => [
                    ['label' => 'Customers', 'route' => 'demo.customers', 'icon' => 'customers'],
                    ['label' => 'Brands', 'route' => 'demo.brands', 'icon' => 'brands'],
                    ['label' => 'Digital Assets', 'route' => 'demo.assets', 'icon' => 'assets'],
                ],
            ],
            [
                'label' => 'Operations',
                'items' => [
                    ['label' => 'Findings', 'route' => 'demo.findings', 'icon' => 'findings'],
                    ['label' => 'Recommendations', 'route' => 'demo.recommendations', 'icon' => 'recommendations'],
                    ['label' => 'Tasks', 'route' => 'demo.tasks', 'icon' => 'tasks'],
                    ['label' => 'Activity', 'route' => 'demo.activity', 'icon' => 'activity'],
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    ['label' => 'Integrations', 'route' => 'demo.integrations', 'icon' => 'integrations'],
                    ['label' => 'Settings', 'route' => 'demo.settings', 'icon' => 'settings'],
                ],
            ],
        ];
    }
}
