<?php

namespace App\Panel\Support;

use Illuminate\Support\Facades\Route;

/**
 * The panel's menu: six entries on the left, each with its screens as tabs. A tab is drawn only when its route
 * exists and the user may open it; an entry without a visible tab is not drawn.
 */
final class PanelMenu
{
    /** One line icons, drawn at 20 px with a 1.7 stroke. */
    private const array ICONS = [
        'home' => '<path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v9h12v-9"/><path d="M10 19v-5h4v5"/>',
        'sales' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 19c.6-3.3 3-5 6-5s5.4 1.7 6 5"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6"/><path d="M18 14.3c1.7.7 2.7 2.3 3 4.7"/>',
        'marketing' => '<path d="M4 19h16"/><path d="M6 15l4-4 3 3 5-6"/><path d="M15 8h3v3"/>',
        'account' => '<rect x="3.5" y="6" width="17" height="12.5" rx="2"/><path d="M3.5 10.5h17"/><path d="M7 15h4"/>',
        'support' => '<path d="M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v8a1.5 1.5 0 0 1-1.5 1.5h-6l-4 3v-3H5A1.5 1.5 0 0 1 3.5 15V7A1.5 1.5 0 0 1 5 5.500z"/>',
        'team' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-3.8 3.5-5.500 7-5.500s6.300 1.700 7 5.500"/>',
    ];

    /**
     * @return list<array{key: string, label: string, sub: string, icon: string, tabs: list<array{0: string, 1: string, 2: string, 3: list<string>}>}>
     *                                                                                                                                                 Each tab: label, route, permission ("manager" for the brand's manager only), the routes that light it.
     */
    public static function all(): array
    {
        return [
            ['key' => 'home', 'label' => 'Özet', 'sub' => '', 'icon' => 'home', 'tabs' => [
                ['Özet', 'panel.home', 'menu.home', ['panel.home']],
            ]],
            ['key' => 'sales', 'label' => 'Satış', 'sub' => 'Size ulaşan herkes ve onlarla yazışmalarınız', 'icon' => 'sales', 'tabs' => [
                ['Adaylar', 'panel.leads', 'menu.leads', ['panel.leads', 'panel.person', 'panel.presets', 'panel.import']],
                ['Gelen kutusu', 'panel.inbox', 'menu.inbox', ['panel.inbox']],
                ['Performans', 'panel.performance', 'menu.performance', ['panel.performance']],
            ]],
            ['key' => 'marketing', 'label' => 'Pazarlama', 'sub' => 'Moximu sizin için ne yaptı, ne sonuç aldı', 'icon' => 'marketing', 'tabs' => [
                ['Aylık rapor', 'panel.report', 'menu.marketing', ['panel.report']],
                ['Yaptıklarımız', 'panel.work', 'menu.marketing', ['panel.work']],
                ['Yayınlanacaklar', 'panel.upcoming', 'menu.marketing', ['panel.upcoming']],
                ['Varlıklar', 'panel.assets', 'menu.marketing', ['panel.assets']],
            ]],
            ['key' => 'account', 'label' => 'Hesap', 'sub' => 'Hizmetleriniz, faturalarınız ve ödemeleriniz', 'icon' => 'account', 'tabs' => [
                ['Hizmetler', 'panel.services', 'manager', ['panel.services']],
                ['Faturalar', 'panel.invoices', 'manager', ['panel.invoices']],
                ['Ödemeler', 'panel.payments', 'manager', ['panel.payments']],
                ['Yenilemeler', 'panel.renewals', 'manager', ['panel.renewals']],
            ]],
            ['key' => 'support', 'label' => 'Destek', 'sub' => 'İstekleriniz, dosyalarınız, toplantılar ve duyurular', 'icon' => 'support', 'tabs' => [
                ['Talepler', 'panel.support', 'menu.support', ['panel.support']],
                ['Dosyalar', 'panel.files', 'menu.support', ['panel.files']],
                ['Toplantılar', 'panel.meetings', 'menu.support', ['panel.meetings']],
                ['Duyurular', 'panel.announcements', 'menu.support', ['panel.announcements']],
            ]],
            ['key' => 'team', 'label' => 'Ekip', 'sub' => 'Panele giren kişiler ve yetkileri', 'icon' => 'team', 'tabs' => [
                ['Ekip', 'panel.users', 'menu.users', ['panel.users']],
            ]],
        ];
    }

    /**
     * What this user sees: entries with their open tabs, the one in use marked.
     *
     * @return list<array{key: string, label: string, sub: string, icon: string, url: string, active: bool, tabs: list<array{label: string, url: string, active: bool}>}>
     */
    public static function for(PanelContext $panel, string $current): array
    {
        $groups = [];
        foreach (self::all() as $group) {
            $tabs = [];
            foreach ($group['tabs'] as [$label, $route, $permission, $routes]) {
                if (! Route::has($route) || ! ($permission === 'manager' ? $panel->isManager() : $panel->can($permission))) {
                    continue;
                }
                $tabs[] = ['label' => $label, 'url' => route($route), 'active' => in_array($current, $routes, true)];
            }
            if ($tabs === []) {
                continue;
            }
            $groups[] = [
                'key' => $group['key'], 'label' => $group['label'], 'sub' => $group['sub'], 'icon' => self::icon($group['icon']),
                'url' => $tabs[0]['url'], 'active' => in_array(true, array_column($tabs, 'active'), true), 'tabs' => $tabs,
            ];
        }

        return $groups;
    }

    public static function icon(string $name): string
    {
        return '<svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(self::ICONS[$name] ?? '').'</svg>';
    }
}
