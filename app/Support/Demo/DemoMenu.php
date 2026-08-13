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
                'label' => __('operator.nav.groups.menu'),
                'items' => [
                    ['label' => __('operator.nav.dashboard'), 'route' => 'demo.dashboard', 'icon' => 'dashboard'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.portfolio'),
                'items' => [
                    ['label' => __('operator.nav.customers'), 'route' => 'demo.customers', 'icon' => 'customers'],
                    ['label' => __('operator.nav.brands'), 'route' => 'demo.brands', 'icon' => 'brands'],
                    ['label' => __('operator.nav.digital_assets'), 'route' => 'demo.assets', 'icon' => 'assets'],
                    ['label' => __('operator.nav.files'), 'route' => 'demo.files', 'icon' => 'files'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.operations'),
                'items' => [
                    ['label' => __('operator.nav.opportunities'), 'route' => 'demo.opportunities', 'icon' => 'recommendations'],
                    ['label' => __('operator.nav.findings'), 'route' => 'demo.findings', 'icon' => 'findings'],
                    ['label' => __('operator.nav.recommendations'), 'route' => 'demo.recommendations', 'icon' => 'recommendations'],
                    ['label' => __('operator.nav.work'), 'route' => 'demo.tasks', 'icon' => 'tasks'],
                    ['label' => __('operator.nav.activity'), 'route' => 'demo.activity', 'icon' => 'activity'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.system'),
                'items' => [
                    ['label' => __('operator.nav.integrations'), 'route' => 'demo.integrations', 'icon' => 'integrations'],
                    ['label' => __('operator.nav.settings'), 'route' => 'demo.settings', 'icon' => 'settings'],
                ],
            ],
        ];
    }
}
