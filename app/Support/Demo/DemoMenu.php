<?php

namespace App\Support\Demo;

use App\Support\Roles;

/**
 * Canonical operator navigation for the TailAdmin operator shell (the class name is legacy).
 *
 * MoxDOP v2 (Faz 0): the sidebar is exactly Bugün · Onarım masası · İçerik · İşletme profilleri · Google Ads · Meta reklamları ·
 * (Kazananlar · Stratejiler · Sorgular left with Aşama 11, 2026-10-22) · Markalar (Müşteriler merged in, 2026-10-19) · Web siteleri (Dijital varlıklar left the menu 2026-10-20: every asset is on its brand page, under Entegrasyonlar too) · WhatsApp (admins) ·
 * Entegrasyonlar · Ayarlar: Çalışma (no heading), Kanallar, Büyüme, Portföy, and Sistem pinned at the bottom (menu
 * prototype approved 2026-10-20).
 * Ayarlar carries the settings screens as tabs: AI işlemleri ve promptlar, Standartlar, Sektör ve hizmet kataloğu,
 * Kullanıcılar, Sistem. Every other screen is reached from a brand / asset page or a direct link.
 */
final class DemoMenu
{
    /**
     * The sidebar holds one entry per job; related screens are its children and show as tabs on each other (W7).
     *
     * @return list<array{label: string, pinned: bool, items: list<array{label: string, route: string, icon: string, children: list<array{label: string, route: string}>, active: list<string>}>}>
     */
    public static function groups(): array
    {
        $tr = app()->getLocale() === 'tr';

        $item = static fn (string $label, string $route, string $icon, array $children = [], array $active = []): array => ['label' => $label, 'route' => $route, 'icon' => $icon, 'children' => $children, 'active' => $active];
        $child = static fn (string $label, string $route): array => ['label' => $label, 'route' => $route];

        $group = static fn (string $label, array $items, bool $pinned = false): array => ['label' => $label, 'items' => $items, 'pinned' => $pinned];

        // Four groups and a pinned Sistem at the bottom (approved menu prototype, yakup 2026-10-20; was six groups since
        // 2026-10-19). The flattened order is locked by PanelDesignFreezeTest.
        return [
            $group($tr ? 'Çalışma' : 'Work', [
                // Aşama 5 (2026-10-22): Komuta replaced Bugün as the home screen; Bugün özeti stays lit under it.
                $item($tr ? 'Komuta' : 'Command', 'operator.dashboard', 'dashboard', [], ['operator.today']),
                // Aşama 4 (2026-10-22): every approval and send, live; the count is what runs now.
                $item($tr ? 'Akış' : 'Flow', 'operator.flow', 'flow'),
                // One work list (2026-10-10): Genel işler is the desk's "Diğer işler" section.
                $item($tr ? 'Onarım masası' : 'Repair desk', 'operator.repair', 'work', [], ['operator.work']),
                // The weekly content cycle (yakup 2026-10-20): written articles go to the sites with one click from here.
                $item($tr ? 'İçerik' : 'Content', 'operator.content', 'content'),
            ]),
            $group($tr ? 'Kanallar' : 'Channels', [
                $item($tr ? 'İşletme profilleri' : 'Business profiles', 'operator.gbp-desk', 'calendar', [], ['operator.gbp-posts', 'operator.gbp-branch-pages', 'operator.gbp-profile-fields', 'operator.gbp-photos', 'operator.gbp-reviews']),
                $item('Google Ads', 'operator.google-ads-desk', 'seo', [], ['operator.google-ads-terms', 'operator.google-ads-changes', 'operator.google-ads-ads', 'operator.google-ads-measurement', 'operator.google-ads-services', 'operator.google-ads.overview']),
                $item($tr ? 'Meta reklamları' : 'Meta ads', 'operator.meta-desk', 'ads-advisor', [], ['operator.meta.overview', 'operator.meta.campaign', 'operator.meta.assign', 'operator.meta-strategy', 'operator.libraries']),
            ]),
            // Aşama 11 (2026-10-22): Büyüme left the menu. Kazananlar lives in Komuta's sector cards, Stratejiler opens from
            // the brand page, Sorgular feeds the content plan; the screens stay reachable by link for a week.
            $group($tr ? 'Portföy' : 'Portfolio', [
                // Aşama 5: every brand's goal, channel health and trend on one list.
                $item($tr ? 'Portföy' : 'Portfolio', 'operator.portfolio', 'portfolio'),
                // Müşteriler merged into Markalar (2026-10-19): every customer has one brand; the customer screens stay
                // reachable from the brand list and keep Markalar lit.
                $item(__('operator.nav.brands'), 'operator.brands', 'brands', [], ['operator.customers', 'operator.customer', 'operator.customer.create', 'operator.customer.edit']),
                $item($tr ? 'Web siteleri' : 'Websites', 'operator.websites', 'website', [], ['operator.website']),
                ...(auth()->user()?->hasRole(Roles::ADMIN) ? [$item('WhatsApp', 'operator.whatsapp', 'chat')] : []),
            ]),
            $group($tr ? 'Sistem' : 'System', [
                $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
                    $child($tr ? 'Hesaplar' : 'Accounts', 'operator.integrations.accounts'),
                    $child($tr ? 'Bağlantı sağlığı' : 'Connection health', 'operator.integrations.connection-health'),
                    $child($tr ? 'Keşfedilen varlıklar' : 'Discovered Assets', 'operator.integrations.discovered'),
                    $child($tr ? 'WordPress siteleri' : 'WordPress Sites', 'operator.integrations.wordpress-sites'),
                    $child($tr ? 'Dijital varlıklar' : 'Digital assets', 'operator.assets'),
                ]),
                $item(__('operator.nav.settings'), 'operator.settings', 'settings', [
                    $child($tr ? 'AI sağlayıcıları' : 'AI providers', 'operator.settings.ai-workers'),
                    $child($tr ? 'AI işlemleri ve promptlar' : 'AI operations & prompts', 'operator.settings.ai-operations'),
                    $child($tr ? 'Standartlar' : 'Standards', 'operator.library.website-standards'),
                    $child($tr ? 'Sektör ve hizmet kataloğu' : 'Sector & service catalog', 'operator.library.services'),
                    $child($tr ? 'Sorgu kuralları' : 'Query rules', 'operator.settings.query-rules'),
                    $child($tr ? 'Kullanıcılar' : 'Users', 'operator.settings.users'),
                    $child($tr ? 'MCP anahtarları' : 'MCP keys', 'operator.settings.mcp'),
                    $child($tr ? 'Sistem' : 'System', 'operator.settings.system-health'),
                    $child($tr ? 'Geliştirme havuzu' : 'Improvements', 'operator.settings.improvements'),
                    $child($tr ? 'Sürümler' : 'Releases', 'operator.settings.releases'),
                ]),
            ], true),
        ];
    }
}
