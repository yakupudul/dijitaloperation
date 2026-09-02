<?php

namespace App\Support\Demo;

/**
 * Canonical operator navigation for the TailAdmin operator shell.
 *
 * The class name is legacy; navigation may point to real operator engine surfaces.
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
                    ['label' => __('operator.nav.dashboard'), 'route' => 'operator.dashboard', 'icon' => 'dashboard'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.portfolio'),
                'items' => [
                    ['label' => __('operator.nav.customers'), 'route' => 'operator.customers', 'icon' => 'customers'],
                    ['label' => __('operator.nav.brands'), 'route' => 'operator.brands', 'icon' => 'brands'],
                    ['label' => __('operator.nav.digital_assets'), 'route' => 'operator.assets', 'icon' => 'assets'],
                    ['label' => app()->getLocale() === 'tr' ? 'Kamu Keşif' : 'Public Discovery', 'route' => 'operator.public-discovery', 'icon' => 'discovery'],
                    ['label' => __('operator.nav.files'), 'route' => 'operator.files', 'icon' => 'files'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.sales'),
                'items' => [
                    ['label' => __('operator.nav.prospects'), 'route' => 'operator.prospects', 'icon' => 'prospects'],
                    ['label' => __('operator.nav.intent_radar'), 'route' => 'operator.intent-radar', 'icon' => 'activity'],
                ],
            ],
            [
                'label' => app()->getLocale() === 'tr' ? 'Kütüphane' : 'Library',
                'items' => [
                    ['label' => app()->getLocale() === 'tr' ? 'Hizmetler' : 'Services', 'route' => 'operator.library.services', 'icon' => 'library'],
                    ['label' => app()->getLocale() === 'tr' ? 'Sorgular' : 'Search Queries', 'route' => 'operator.library.search-queries', 'icon' => 'search'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.operations'),
                'items' => [
                    ['label' => __('operator.nav.opportunities'), 'route' => 'operator.opportunities', 'icon' => 'recommendations'],
                    ['label' => __('operator.nav.findings'), 'route' => 'operator.findings', 'icon' => 'findings'],
                    ['label' => __('operator.nav.recommendations'), 'route' => 'operator.recommendations', 'icon' => 'recommendations'],
                    ['label' => __('operator.nav.work'), 'route' => 'operator.tasks', 'icon' => 'tasks'],
                    ['label' => __('operator.nav.activity'), 'route' => 'operator.activity', 'icon' => 'activity'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.system'),
                'items' => [
                    ['label' => __('operator.nav.integrations'), 'route' => 'operator.integrations', 'icon' => 'integrations'],
                    ['label' => __('operator.nav.settings'), 'route' => 'operator.settings', 'icon' => 'settings'],
                    ['label' => __('background_operations.title'), 'route' => 'operator.settings.background-operations', 'icon' => 'activity'],
                ],
            ],
        ];
    }
}
