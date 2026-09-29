<?php

namespace App\Support\Demo;

use App\Support\Roles;

/**
 * Canonical operator navigation for the TailAdmin operator shell.
 *
 * The class name is legacy. Step 3 (brand workspace): the daily work happens on the brand screen (Arama · Harita ·
 * Google Ads · Meta), so the sidebar is only Bugün · Markalar · Müşteriler · Sorgular · Entegrasyonlar · Ayarlar.
 * Every other screen (Komuta merkezi, Portföy sağlığı, Danışman, SEO görevleri, İçerik takvimi, Rakipler, Hizmet
 * Beyni, Lead kutusu, Potansiyel müşteriler, Aylık rapor, Ajans işletmesi, WhatsApp, Yenilemeler, Dijital varlıklar…)
 * keeps its route and is reached from the brand workspace / brand Ayarlar or a direct link — not from the menu.
 */
final class DemoMenu
{
    /**
     * The sidebar holds one entry per job; related screens are its children and show as tabs on each other (W7).
     *
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, children: list<array{label: string, route: string}>}>}>
     */
    public static function groups(): array
    {
        $tr = app()->getLocale() === 'tr';
        $isAdmin = (bool) (auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN));

        $item = static fn (string $label, string $route, string $icon, array $children = []): array => ['label' => $label, 'route' => $route, 'icon' => $icon, 'children' => $children];
        $child = static fn (string $label, string $route): array => ['label' => $label, 'route' => $route];

        return [
            [
                'label' => __('operator.nav.groups.menu'),
                'items' => [
                    $item($tr ? 'Bugün' : 'Today', 'operator.dashboard', 'dashboard'),
                    $item(__('operator.nav.brands'), 'operator.brands', 'brands'),
                    $item(__('operator.nav.customers'), 'operator.customers', 'customers'),
                    $item($tr ? 'Sorgular' : 'Search Queries', 'operator.library.search-queries', 'search', [
                        $child($tr ? 'Hizmetler' : 'Services', 'operator.library.services'),
                    ]),
                ],
            ],
            [
                'label' => __('operator.nav.groups.system'),
                'items' => [
                    $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
                        $child($tr ? 'Keşfedilen varlıklar' : 'Discovered Assets', 'operator.integrations.discovered'),
                        $child($tr ? 'WordPress siteleri' : 'WordPress Sites', 'operator.integrations.wordpress-sites'),
                        $child($tr ? 'Kopya web siteleri' : 'Duplicate Websites', 'operator.integrations.website-duplicates'),
                        $child($tr ? 'Veri merkezi' : 'Data Center', 'operator.data-center'),
                    ]),
                    $item(__('operator.nav.settings'), 'operator.settings', 'settings', [
                        $child(__('operator.nav.compliance'), 'operator.compliance'),
                        $child(__('operator.nav.activity'), 'operator.activity'),
                        ...($isAdmin ? [$child($tr ? 'AI kalitesi' : 'AI Quality', 'operator.settings.ai-quality')] : []),
                    ]),
                ],
            ],
        ];
    }
}
