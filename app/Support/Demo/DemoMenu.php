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
                    $item(__('operator.nav.dashboard'), 'operator.dashboard', 'dashboard'),
                    $item($tr ? 'Komuta merkezi' : 'Command Center', 'operator.command-center', 'command', [
                        $child($tr ? 'İş listesi' : 'Work List', 'operator.tasks'),
                        $child($tr ? 'Uyarılar' : 'Alerts', 'operator.alerts'),
                        $child(__('operator.nav.renewals'), 'operator.renewals'),
                    ]),
                ],
            ],
            [
                'label' => __('operator.nav.groups.portfolio'),
                'items' => [
                    $item($tr ? 'Portföy sağlığı' : 'Portfolio Health', 'operator.portfolio.health', 'health'),
                    $item(__('operator.nav.customers'), 'operator.customers', 'customers'),
                    $item(__('operator.nav.brands'), 'operator.brands', 'brands'),
                    $item(__('operator.nav.digital_assets'), 'operator.assets', 'assets'),
                ],
            ],
            [
                'label' => __('operator.nav.work'),
                'items' => [
                    // Both open the Komuta merkezi inbox pre-filtered; the full panel is the "Ayrıntılı ekran" tab.
                    $item(__('operator.nav.ads_advisor'), 'operator.ads_advisor', 'ads-advisor', [
                        $child($tr ? 'Ayrıntılı ekran' : 'Detailed View', 'operator.ads_advisor.detailed'),
                    ]),
                    $item(__('operator.nav.seo_tasks'), 'operator.seo_tasks', 'seo', [
                        $child($tr ? 'Ayrıntılı ekran' : 'Detailed View', 'operator.seo_tasks.detailed'),
                    ]),
                    $item($tr ? 'İçerik takvimi' : 'Content Calendar', 'operator.content.calendar', 'calendar'),
                ],
            ],
            [
                'label' => $tr ? 'Pazar' : 'Market',
                'items' => [
                    $item($tr ? 'Sorgular' : 'Search Queries', 'operator.library.search-queries', 'search', [
                        $child($tr ? 'Hizmetler' : 'Services', 'operator.library.services'),
                    ]),
                    $item($tr ? 'Rakipler' : 'Competitors', 'operator.library.search-demand-competitors', 'competitors', [
                        $child($tr ? 'Harita sıralaması' : 'Map Rankings', 'operator.market.map-rankings'),
                        $child($tr ? 'Rakip izleme' : 'Competitor Watch', 'operator.market.competitor-watch'),
                        $child($tr ? 'Backlink fırsatları' : 'Backlinks', 'operator.market.backlinks'),
                        $child($tr ? 'AI görünürlüğü' : 'AI Visibility', 'operator.market.ai-visibility'),
                    ]),
                    $item($tr ? 'Hizmet Beyni' : 'Service Brain', 'operator.brain.services', 'brain-map', [
                        $child($tr ? 'Beyin önerileri' : 'Brain Recommendations', 'operator.brain.recommendations'),
                        $child($tr ? 'Yöntemler' : 'Methods', 'operator.brain.methods'),
                        $child($tr ? 'Onay kuyruğu' : 'Review Queue', 'operator.brain.proposals'),
                    ]),
                ],
            ],
            [
                'label' => __('operator.nav.groups.sales'),
                'items' => [
                    $item($tr ? 'Lead kutusu' : 'Lead Inbox', 'operator.leads', 'inbox'),
                    $item(__('operator.nav.prospects'), 'operator.prospects', 'prospects', [
                        $child(__('operator.nav.intent_radar'), 'operator.intent-radar'),
                        ...($isAdmin ? [$child($tr ? 'WhatsApp Asistanı' : 'WhatsApp Assistant', 'operator.whatsapp')] : []),
                    ]),
                ],
            ],
            [
                'label' => $tr ? 'Raporlar' : 'Reports',
                'items' => [
                    $item($tr ? 'Aylık rapor' : 'Monthly Report', 'operator.reports.monthly', 'report', [
                        $child($tr ? 'Rapor kuyruğu' : 'Report Queue', 'operator.reports.queue'),
                        $child($tr ? 'Ajans karnesi' : 'Monthly Results', 'operator.reports.scorecard'),
                        $child($tr ? 'Grafik notları' : 'Chart Notes', 'operator.reports.annotations'),
                        $child(__('operator.nav.archive'), 'operator.archive'),
                    ]),
                    $item($tr ? 'Ajans işletmesi' : 'Agency Business', 'operator.agency', 'finance'),
                ],
            ],
            [
                'label' => __('operator.nav.groups.system'),
                'items' => [
                    $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
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
