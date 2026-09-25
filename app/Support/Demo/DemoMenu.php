<?php

namespace App\Support\Demo;

use App\Support\Roles;

/**
 * Canonical operator navigation for the TailAdmin operator shell.
 *
 * The class name is legacy; navigation may point to real operator engine surfaces. Faz 10e "sade menü"
 * (docs/product/MOXDOP_STRATEGY_ROADMAP.md): Opportunities, Findings, Recommendations, Public Discovery, Files,
 * Query Clusters and Background Operations left the sidebar; their routes stay and are linked from their parent
 * screens (Settings, Search Queries, brand setup).
 */
final class DemoMenu
{
    /**
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string}>}>
     */
    public static function groups(): array
    {
        $tr = app()->getLocale() === 'tr';
        $isAdmin = (bool) (auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN));

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
                ],
            ],
            [
                'label' => __('operator.nav.work'),
                'items' => [
                    ['label' => $tr ? 'İş listesi' : 'Work List', 'route' => 'operator.tasks', 'icon' => 'tasks'],
                    ['label' => __('operator.nav.ads_advisor'), 'route' => 'operator.ads_advisor', 'icon' => 'ads-advisor'],
                    ['label' => __('operator.nav.seo_tasks'), 'route' => 'operator.seo_tasks', 'icon' => 'seo'],
                    ['label' => $tr ? 'Uyarılar' : 'Alerts', 'route' => 'operator.alerts', 'icon' => 'alerts'],
                    ['label' => __('operator.nav.renewals'), 'route' => 'operator.renewals', 'icon' => 'renewals'],
                ],
            ],
            [
                'label' => $tr ? 'Pazar' : 'Market',
                'items' => [
                    ['label' => $tr ? 'Sorgular' : 'Search Queries', 'route' => 'operator.library.search-queries', 'icon' => 'search'],
                    ['label' => $tr ? 'Hizmetler' : 'Services', 'route' => 'operator.library.services', 'icon' => 'library'],
                    ['label' => $tr ? 'Rakipler' : 'Competitors', 'route' => 'operator.library.search-demand-competitors', 'icon' => 'competitors'],
                    ['label' => $tr ? 'Harita sıralaması' : 'Map Rankings', 'route' => 'operator.market.map-rankings', 'icon' => 'map'],
                    ['label' => $tr ? 'Backlink fırsatları' : 'Backlinks', 'route' => 'operator.market.backlinks', 'icon' => 'links'],
                    ['label' => $tr ? 'Rakip izleme' : 'Competitor Watch', 'route' => 'operator.market.competitor-watch', 'icon' => 'watch'],
                    ['label' => $tr ? 'AI görünürlüğü' : 'AI Visibility', 'route' => 'operator.market.ai-visibility', 'icon' => 'ai'],
                ],
            ],
            [
                'label' => $tr ? 'Hizmet Beyni' : 'Service Brain',
                'items' => [
                    ['label' => $tr ? 'Hizmet haritası' : 'Service Map', 'route' => 'operator.brain.services', 'icon' => 'brain-map'],
                    ['label' => $tr ? 'Beyin önerileri' : 'Brain Recommendations', 'route' => 'operator.brain.recommendations', 'icon' => 'brain-recs'],
                    ['label' => $tr ? 'Yöntemler' : 'Methods', 'route' => 'operator.brain.methods', 'icon' => 'brain-methods'],
                    ['label' => $tr ? 'Onay kuyruğu' : 'Review Queue', 'route' => 'operator.brain.proposals', 'icon' => 'brain-queue'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.sales'),
                'items' => [
                    ['label' => $tr ? 'Lead kutusu' : 'Lead Inbox', 'route' => 'operator.leads', 'icon' => 'inbox'],
                    ['label' => __('operator.nav.prospects'), 'route' => 'operator.prospects', 'icon' => 'prospects'],
                    ['label' => __('operator.nav.intent_radar'), 'route' => 'operator.intent-radar', 'icon' => 'radar'],
                    ...($isAdmin ? [
                        ['label' => $tr ? 'WhatsApp Asistanı' : 'WhatsApp Assistant', 'route' => 'operator.whatsapp', 'icon' => 'chat'],
                    ] : []),
                ],
            ],
            [
                'label' => $tr ? 'Raporlar' : 'Reports',
                'items' => [
                    ['label' => $tr ? 'Aylık rapor' : 'Monthly Report', 'route' => 'operator.reports.monthly', 'icon' => 'report'],
                    ['label' => $tr ? 'Grafik notları' : 'Chart Notes', 'route' => 'operator.reports.annotations', 'icon' => 'notes'],
                    ['label' => __('operator.nav.archive'), 'route' => 'operator.archive', 'icon' => 'archive'],
                    ['label' => __('operator.nav.compliance'), 'route' => 'operator.compliance', 'icon' => 'compliance'],
                    ['label' => __('operator.nav.activity'), 'route' => 'operator.activity', 'icon' => 'activity'],
                ],
            ],
            [
                'label' => __('operator.nav.groups.system'),
                'items' => [
                    ['label' => __('operator.nav.integrations'), 'route' => 'operator.integrations', 'icon' => 'integrations'],
                    ['label' => $tr ? 'WordPress siteleri' : 'WordPress Sites', 'route' => 'operator.integrations.wordpress-sites', 'icon' => 'wordpress'],
                    ['label' => __('operator.nav.settings'), 'route' => 'operator.settings', 'icon' => 'settings'],
                ],
            ],
        ];
    }
}
