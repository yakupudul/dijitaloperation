<?php

namespace App\Support\Demo;

use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
        // A child may name its group (Ayarlar: Ajans / Kurallar / Makine): the page's tabs then show the groups first, the group's own screens under them.
        $child = static fn (string $label, string $route, ?string $group = null): array => ['label' => $label, 'route' => $route] + ($group !== null ? ['group' => $group] : []);

        $group = static fn (string $label, array $items, bool $pinned = false): array => ['label' => $label, 'items' => $items, 'pinned' => $pinned];
        $own = self::agencyBrand();

        // Menü düzeni 2026-11-09 (yakup "yap", thread "MoxDOP panel menüsünü iyileştir"): order follows the work's weight.
        // Günüm (the day) · Moximu (money in) · Müşteri işleri (brands and the work done for them) · Kanallar · Sistem
        // pinned. Portföy and Web siteleri are tabs of Markalar (three lists of the same brands were three entries);
        // Akış left the menu: the top bar's "N çalışıyor" pill opens it. The flattened order is locked by
        // PanelDesignFreezeTest.
        return [
            $group($tr ? 'Günüm' : 'My day', [
                // Aşama 5 (2026-10-22): Komuta replaced Bugün as the home screen; Bugün özeti stays lit under it.
                $item($tr ? 'Komuta' : 'Command', 'operator.dashboard', 'dashboard', [], ['operator.today']),
                // Takvim (yakup 2026-11-05): the center of the day; own tasks, meetings, sales steps and brands' work.
                $item($tr ? 'Takvim' : 'Calendar', 'operator.calendar', 'agenda'),
            ]),
            // Moximu (yakup 2026-11-06: "bizim Moximu markamızla alakalı bölümleri aynı menü altında topla"): the agency's
            // own work. The brand and site entries are shortcuts to the same screens, opened on the agency's brand.
            $group($own['name'] ?? 'Moximu', [
                // Satış (2026-11-04): prospects, today's messages, customers' requests, results.
                $item($tr ? 'Satış' : 'Sales', 'operator.sales', 'prospects', [], ['operator.sales.lead']),
                // Finans (2026-11-12): payment tracking of every paid job, invoices and expenses. Admin only.
                ...(auth()->user()?->hasRole(Roles::ADMIN) ? [$item($tr ? 'Finans' : 'Finance', 'operator.finance', 'finance', [], ['operator.finance.document'])] : []),
                // Gelen kutusu (Aşama 2, 2026-11-07): the day's one queue; the WhatsApp screen (chats, Beyin, connection) lights it too.
                ...(auth()->user()?->hasRole(Roles::ADMIN) ? [$item($tr ? 'Gelen kutusu' : 'Inbox', 'operator.inbox', 'chat', [], ['operator.whatsapp'])] : []),
                // Pazarlama (Aşama 4, 2026-11-07): the funnel by source; Sosyal medya (ADR-083) lives inside it as a tab and
                // keeps its own screen for the post plan, lit under this entry.
                $item($tr ? 'Pazarlama' : 'Marketing', 'operator.marketing', 'social', [], ['operator.social']),
                ...($own !== null && $own['website'] !== null
                    ? [['label' => $tr ? 'Web sitemiz' : 'Our website', 'route' => 'operator.website', 'params' => ['assetId' => $own['website']], 'icon' => 'website', 'children' => [], 'active' => []]]
                    : []),
                ...($own !== null
                    ? [['label' => $tr ? 'Marka kartımız' : 'Our brand', 'route' => 'operator.brand', 'params' => ['brand' => $own['id']], 'icon' => 'brands', 'children' => [], 'active' => []]]
                    : []),
            ]),
            $group($tr ? 'Müşteri işleri' : 'Client work', [
                // Markalar (Müşteriler merged in 2026-10-19) carries Portföy (goal, channels, trend) and Web siteleri as tabs.
                $item(__('operator.nav.brands'), 'operator.brands', 'brands', [
                    $child($tr ? 'Portföy' : 'Portfolio', 'operator.portfolio'),
                    $child($tr ? 'Web siteleri' : 'Websites', 'operator.websites'),
                ], ['operator.customers', 'operator.customer', 'operator.customer.create', 'operator.customer.edit', 'operator.website']),
                // One work list (2026-10-10): Genel işler is the desk's "Diğer işler" section.
                $item($tr ? 'Onarım masası' : 'Repair desk', 'operator.repair', 'work', [], ['operator.work']),
                // The weekly content cycle (yakup 2026-10-20): written articles go to the sites with one click from here.
                $item($tr ? 'İçerik' : 'Content', 'operator.content', 'content'),
                // Bağlantılar (Backlink hizmeti, 2026-11-06): link targets, the desk of ready applications, the shared pool.
                $item($tr ? 'Bağlantılar' : 'Links', 'operator.links', 'link'),
            ]),
            $group($tr ? 'Kanallar' : 'Channels', [
                $item($tr ? 'İşletme profilleri' : 'Business profiles', 'operator.gbp-desk', 'calendar', [], ['operator.gbp-posts', 'operator.gbp-branch-pages', 'operator.gbp-profile-fields', 'operator.gbp-photos', 'operator.gbp-reviews']),
                $item('Google Ads', 'operator.google-ads-desk', 'seo', [], ['operator.google-ads-terms', 'operator.google-ads-changes', 'operator.google-ads-ads', 'operator.google-ads-measurement', 'operator.google-ads-services', 'operator.google-ads.overview']),
                $item($tr ? 'Meta reklamları' : 'Meta ads', 'operator.meta-desk', 'ads-advisor', [], ['operator.meta.overview', 'operator.meta.campaign', 'operator.meta.assign', 'operator.meta-strategy', 'operator.libraries']),
            ]),
            $group($tr ? 'Sistem' : 'System', [
                $item(__('operator.nav.integrations'), 'operator.integrations', 'integrations', [
                    $child($tr ? 'Hesaplar' : 'Accounts', 'operator.integrations.accounts'),
                    $child($tr ? 'Bağlantı sağlığı' : 'Connection health', 'operator.integrations.connection-health'),
                    $child($tr ? 'Dijital varlıklar' : 'Digital assets', 'operator.assets'),
                ]),
                // Bağlantı Düzeni 2.3 (2026-11-11): three groups instead of a row of tabs. Ajans = who we are, Kurallar = how the
                // work is done, Makine = what keeps the system running (it only asks for attention when something is wrong).
                $item(__('operator.nav.settings'), 'operator.settings', 'settings', [
                    $child($tr ? 'Kullanıcılar' : 'Users', 'operator.settings.users', $tr ? 'Ajans' : 'Agency'),
                    $child($tr ? 'Standartlar' : 'Standards', 'operator.library.website-standards', $tr ? 'Kurallar' : 'Rules'),
                    $child($tr ? 'Sektör ve hizmet kataloğu' : 'Sector & service catalog', 'operator.library.services', $tr ? 'Kurallar' : 'Rules'),
                    $child($tr ? 'Sorgu kuralları' : 'Query rules', 'operator.settings.query-rules', $tr ? 'Kurallar' : 'Rules'),
                    $child($tr ? 'Kitaplar' : 'Books', 'operator.settings.books', $tr ? 'Kurallar' : 'Rules'),
                    $child($tr ? 'AI sağlayıcıları' : 'AI providers', 'operator.settings.ai-workers', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'AI işlemleri ve promptlar' : 'AI operations & prompts', 'operator.settings.ai-operations', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'MCP anahtarları' : 'MCP keys', 'operator.settings.mcp', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Güvenlik karnesi' : 'Security card', 'operator.settings.security', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Sistem' : 'System', 'operator.settings.system-health', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Zamanlanmış işler' : 'Scheduled jobs', 'operator.settings.jobs', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Değişiklik kaydı' : 'Change log', 'operator.settings.changes', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Geliştirme havuzu' : 'Improvements', 'operator.settings.improvements', $tr ? 'Makine' : 'Machine'),
                    $child($tr ? 'Sürümler' : 'Releases', 'operator.settings.releases', $tr ? 'Makine' : 'Machine'),
                ]),
            ], true),
        ];
    }

    /**
     * The agency's own brand (named like Ayarlar › Genel's agency name, else "Moximu") and its first website, for the
     * Moximu group's shortcuts; null when there is none. Cached for a minute (the menu renders on every screen).
     *
     * @return array{id: int, name: string, website: ?int}|null
     */
    public static function agencyBrand(): ?array
    {
        try {
            return Cache::remember('demo-menu.agency-brand', 60, function (): ?array {
                $name = trim((string) (AgencySetting::query()->value('agency_name') ?? '')) ?: 'Moximu';
                $brand = Brand::query()->where('name', $name)->first(['id', 'name'])
                    ?? Brand::query()->where('name', 'Moximu')->first(['id', 'name']);
                if ($brand === null) {
                    return null;
                }
                $website = DigitalAsset::query()->where('type', 'website')->where('brand_id', $brand->id)->orderBy('id')->value('id');

                return ['id' => (int) $brand->id, 'name' => (string) $brand->name, 'website' => $website !== null ? (int) $website : null];
            });
        } catch (Throwable) {
            return null;
        }
    }
}
