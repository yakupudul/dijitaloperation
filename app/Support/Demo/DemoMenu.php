<?php

namespace App\Support\Demo;

use App\Support\Roles;

/**
 * Canonical operator navigation for the TailAdmin operator shell (the class name is legacy).
 *
 * MoxDOP v2 (Faz 0): the sidebar is exactly Bugün · Genel işler · Müşteriler · Markalar · Dijital varlıklar · Web siteleri ·
 * Sorgular · WhatsApp (admins) · Entegrasyonlar · Ayarlar.
 * Ayarlar carries the settings screens as tabs: AI işlemleri ve promptlar, Standartlar, Sektör ve hizmet kataloğu,
 * Kullanıcılar, Sistem. Every other screen is reached from a brand / asset page or a direct link.
 */
final class DemoMenu
{
    /**
     * The sidebar holds one entry per job; related screens are its children and show as tabs on each other (W7).
     *
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, children: list<array{label: string, route: string}>, active: list<string>}>}>
     */
    public static function groups(): array
    {
        $tr = app()->getLocale() === 'tr';

        $item = static fn (string $label, string $route, string $icon, array $children = [], array $active = []): array => ['label' => $label, 'route' => $route, 'icon' => $icon, 'children' => $children, 'active' => $active];
        $child = static fn (string $label, string $route): array => ['label' => $label, 'route' => $route];

        return [
            [
                'label' => __('operator.nav.groups.menu'),
                'items' => [
                    $item($tr ? 'Bugün' : 'Today', 'operator.dashboard', 'dashboard'),
                    $item($tr ? 'Genel işler' : 'Work', 'operator.work', 'work'),
                    $item(__('operator.nav.customers'), 'operator.customers', 'customers'),
                    $item(__('operator.nav.brands'), 'operator.brands', 'brands'),
                    $item($tr ? 'Dijital varlıklar' : 'Digital assets', 'operator.assets', 'assets', [], ['operator.asset.create', 'operator.asset.edit']),
                    $item($tr ? 'Web siteleri' : 'Websites', 'operator.websites', 'website', [], ['operator.website']),
                    $item($tr ? 'Sorgular' : 'Queries', 'operator.library.queries', 'search'),
                    ...(auth()->user()?->hasRole(Roles::ADMIN) ? [$item('WhatsApp', 'operator.whatsapp', 'chat')] : []),
                    $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
                        $child($tr ? 'Keşfedilen varlıklar' : 'Discovered Assets', 'operator.integrations.discovered'),
                        $child($tr ? 'WordPress siteleri' : 'WordPress Sites', 'operator.integrations.wordpress-sites'),
                        $child($tr ? 'Veri merkezi' : 'Data Center', 'operator.data-center'),
                    ]),
                    $item(__('operator.nav.settings'), 'operator.settings', 'settings', [
                        $child($tr ? 'AI işlemleri ve promptlar' : 'AI operations & prompts', 'operator.settings.ai-operations'),
                        $child($tr ? 'Standartlar' : 'Standards', 'operator.library.website-standards'),
                        $child($tr ? 'Sektör ve hizmet kataloğu' : 'Sector & service catalog', 'operator.library.services'),
                        $child($tr ? 'Kullanıcılar' : 'Users', 'operator.settings.users'),
                        $child($tr ? 'Sistem' : 'System', 'operator.settings.system-health'),
                        $child($tr ? 'Geliştirme havuzu' : 'Improvements', 'operator.settings.improvements'),
                    ]),
                ],
            ],
        ];
    }
}
