<?php

namespace App\Support\Demo;

use App\Support\Roles;

/**
 * Canonical operator navigation for the TailAdmin operator shell (the class name is legacy).
 *
 * MoxDOP v2 (Faz 0): the sidebar is exactly Bugün · Onarım masası · İşletme profilleri · Google Ads · Meta reklamları ·
 * Kazananlar · Stratejiler · Müşteriler · Markalar · Dijital varlıklar · Web siteleri · Sorgular · WhatsApp (admins) ·
 * Entegrasyonlar · Ayarlar, shown in six small groups (Çalışma, Kanallar, Büyüme, Portföy, Araçlar, Sistem) since the
 * panel redesign of 2026-10-19.
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

        $group = static fn (string $label, array $items): array => ['label' => $label, 'items' => $items];

        // Grouped sidebar (panel redesign 2026-10-19). The flattened order is locked by PanelDesignFreezeTest.
        return [
            $group($tr ? 'Çalışma' : 'Work', [
                $item($tr ? 'Bugün' : 'Today', 'operator.dashboard', 'dashboard'),
                // One work list (2026-10-10): Genel işler is the desk's "Diğer işler" section.
                $item($tr ? 'Onarım masası' : 'Repair desk', 'operator.repair', 'work', [], ['operator.work']),
            ]),
            $group($tr ? 'Kanallar' : 'Channels', [
                $item($tr ? 'İşletme profilleri' : 'Business profiles', 'operator.gbp-desk', 'calendar', [], ['operator.gbp-posts', 'operator.gbp-branch-pages', 'operator.gbp-profile-fields', 'operator.gbp-photos', 'operator.gbp-reviews']),
                $item('Google Ads', 'operator.google-ads-desk', 'seo', [], ['operator.google-ads-terms', 'operator.google-ads-changes', 'operator.google-ads-ads', 'operator.google-ads-measurement', 'operator.google-ads-services', 'operator.google-ads.overview']),
                $item($tr ? 'Meta reklamları' : 'Meta ads', 'operator.meta-desk', 'ads-advisor', [], ['operator.meta.overview', 'operator.meta.campaign', 'operator.meta.assign', 'operator.meta-strategy']),
            ]),
            $group($tr ? 'Büyüme' : 'Growth', [
                $item($tr ? 'Kazananlar' : 'Winners', 'operator.winners', 'trophy', [], ['operator.winner-service', 'operator.libraries']),
                $item($tr ? 'Stratejiler' : 'Strategies', 'operator.strategies', 'strategy', [], ['operator.strategy']),
            ]),
            $group($tr ? 'Portföy' : 'Portfolio', [
                $item(__('operator.nav.customers'), 'operator.customers', 'customers'),
                $item(__('operator.nav.brands'), 'operator.brands', 'brands'),
                $item($tr ? 'Dijital varlıklar' : 'Digital assets', 'operator.assets', 'assets', [], ['operator.asset.create', 'operator.asset.edit']),
                $item($tr ? 'Web siteleri' : 'Websites', 'operator.websites', 'website', [], ['operator.website']),
            ]),
            $group($tr ? 'Araçlar' : 'Tools', [
                $item($tr ? 'Sorgular' : 'Queries', 'operator.library.queries', 'search'),
                ...(auth()->user()?->hasRole(Roles::ADMIN) ? [$item('WhatsApp', 'operator.whatsapp', 'chat')] : []),
            ]),
            $group($tr ? 'Sistem' : 'System', [
                $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
                    $child($tr ? 'Bağlantı sağlığı' : 'Connection health', 'operator.integrations.connection-health'),
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
                    $child($tr ? 'Sürümler' : 'Releases', 'operator.settings.releases'),
                ]),
            ]),
        ];
    }
}
