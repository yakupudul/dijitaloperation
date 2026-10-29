<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\GbpQueuedPost;
use App\Models\WhatsAppConversation;
use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use App\Services\Meta\MetaDesk;
use App\Services\Operations\SystemHealthReader;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\PortfolioSignalsReader;
use App\Services\Repair\RepairDesk;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\Work\FlowFeed;
use App\Support\Demo\DemoMenu;
use Illuminate\Support\Facades\Cache;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Sidebar menu for the canonical TailAdmin operator shell.
 * Delegates to DemoMenu for the full-product demo navigation.
 */
final class OperatorMenu
{
    /**
     * @return list<array{title: string, pinned: bool, items: list<array{name: string, path: string, icon: string, routes: list<string>, active: bool, count: ?int, tone: ?string, external?: bool}>}>
     */
    public static function groups(?string $currentPath = null, ?string $routeName = null): array
    {
        $counts = self::counters();
        $badges = self::badges();
        $groups = [];
        foreach (DemoMenu::groups() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $items[] = [
                    'name' => $item['label'],
                    'path' => route($item['route'], absolute: false),
                    'icon' => $item['icon'],
                    'routes' => [$item['route'], ...array_column($item['children'] ?? [], 'route'), ...($item['active'] ?? [])],
                    'count' => $item['route'] === 'operator.flow' ? self::flowRunning() : ($counts[$item['route']] ?? $badges[$item['route']]['count'] ?? null),
                    'tone' => $item['route'] === 'operator.flow' ? 'live' : (isset($counts[$item['route']]) ? 'accent' : ($badges[$item['route']]['tone'] ?? null)),
                ];
            }
            $groups[] = [
                'title' => $group['label'],
                'pinned' => (bool) ($group['pinned'] ?? false),
                'items' => $items,
            ];
        }

        $currentPath ??= '/'.ltrim((string) request()->path(), '/');
        $routeName ??= request()->route()?->getName();
        $active = self::activeEntry(array_merge(...array_column($groups, 'items')), $currentPath, $routeName);
        foreach ($groups as $g => $group) {
            foreach ($group['items'] as $i => $item) {
                $groups[$g]['items'][$i]['active'] = $active !== null && $item['path'] === $active;
            }
        }

        return $groups;
    }

    /**
     * Counters shown at the end of a menu entry: fixes waiting for approval on Onarım masası. Cached briefly and
     * failure-safe, so a broken desk never breaks the shell.
     *
     * @return array<string, int> route name => count (only entries with something to show)
     */
    public static function counters(): array
    {
        if (! auth()->check()) {
            return [];
        }
        try {
            $repair = app(RepairDesk::class)->summary()['decide'];
        } catch (Throwable $error) {
            report($error);

            return [];
        }

        return $repair > 0 ? ['operator.repair' => $repair] : [];
    }

    /** Akış's entry: what runs right now (shared with the top bar pill for a few seconds); null when nothing runs. */
    public static function flowRunning(): ?int
    {
        if (! auth()->check()) {
            return null;
        }
        try {
            $run = (int) (Cache::remember('flow-feed.pill', 8, fn (): array => app(FlowFeed::class)->counts())['run'] ?? 0);
        } catch (Throwable $error) {
            report($error);

            return null;
        }

        return $run > 0 ? $run : null;
    }

    private const BADGES_KEY = 'operator-menu.badges';

    /** Badges older than this are refreshed after the response; the menu keeps showing the last ones meanwhile. */
    private const BADGES_FRESH_SECONDS = 300;

    /**
     * Menu badges (yakup 2026-10-20, approved menu prototype): where work waits. Computing them reads several desks,
     * so a page never waits for it: the last result is shown and a stale one is refreshed after the response.
     *
     * @return array<string, array{count: ?int, tone: string}> route name => badge
     */
    public static function badges(): array
    {
        if (! auth()->check()) {
            return [];
        }
        $cached = Cache::get(self::BADGES_KEY);
        $stale = ! is_array($cached) || (int) ($cached['at'] ?? 0) < now()->getTimestamp() - self::BADGES_FRESH_SECONDS;
        if ($stale && ! app()->runningUnitTests() && Cache::add(self::BADGES_KEY.'.lock', 1, 120)) {
            defer(static function (): void {
                Cache::forever(self::BADGES_KEY, ['at' => now()->getTimestamp(), 'badges' => self::computeBadges()]);
                Cache::forget(self::BADGES_KEY.'.lock');
            });
        }

        return is_array($cached) ? (array) ($cached['badges'] ?? []) : [];
    }

    /**
     * Red = money or data being lost, amber = brands needing a look, soft = information, dot = a broken connection.
     * Each source is failure-safe on its own.
     *
     * @return array<string, array{count: ?int, tone: string}>
     */
    public static function computeBadges(): array
    {
        $out = [];
        $put = static function (string $route, string $tone, callable $count) use (&$out): void {
            try {
                $value = $count();
                if ($value === true) {
                    $out[$route] = ['count' => null, 'tone' => $tone];
                } elseif (is_int($value) && $value > 0) {
                    $out[$route] = ['count' => $value, 'tone' => $tone];
                }
            } catch (Throwable $error) {
                report($error);
            }
        };

        $put('operator.google-ads-desk', 'red', function (): int {
            $desk = app(GoogleAdsDesk::class);

            return count(array_unique(array_column(GoogleAdsDesk::problems($desk->status($desk->accounts())), 'brand')));
        });
        $put('operator.meta-desk', 'red', fn (): int => (int) (app(MetaDesk::class)->desk([])['kpis']['today'] ?? 0));
        $put('operator.gbp-desk', 'soft', fn (): int => GbpQueuedPost::query()->where('status', GbpQueuedPost::DRAFT)
            ->where('publish_on', '>=', now()->toDateString())->count());
        $put('operator.brands', 'amber', function (): int {
            $models = Brand::query()->with(['customer', 'responsibleUsers', 'digitalAssets.assetBindings', 'intelligenceContext', 'sectorCategory'])->get();
            $signals = app(PortfolioSignalsReader::class)->forBrands($models)['brands'];

            return $models->filter(fn (Brand $brand): bool => (bool) (OperatorPortfolioPresenter::brand($brand, $signals[(int) $brand->id] ?? PortfolioSignalsReader::emptyBrand())['needs_attention'] ?? false))->count();
        });
        $put('operator.whatsapp', 'soft', fn (): int => WhatsAppConversation::query()
            ->whereIn('integration_id', CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->select('id'))->whereNull('done_at')->whereNull('opted_out_at')
            ->whereNotNull('last_incoming_at')->whereColumn('last_incoming_at', '>=', 'last_message_at')
            ->where('last_message_at', '>=', now()->subDays(7))->count());
        $put('operator.integrations', 'dot', function (): bool {
            $health = app(SystemHealthReader::class)->read();
            foreach ($health['integrations'] as $integration) {
                if (in_array($integration['provider'], ['google', 'meta'], true) && $integration['auth_status'] !== '' && $integration['auth_status'] !== 'connected') {
                    return true;
                }
                if ($integration['expires_in_days'] !== null && $integration['expires_in_days'] < 0) {
                    return true;
                }
            }

            return collect($health['accounts'])->contains(fn (array $a): bool => $a['enabled'] && $a['state'] === 'attention');
        });

        return $out;
    }

    /**
     * Brands of the sidebar switcher (every operational brand, by name). Cached briefly; empty when the table is missing.
     *
     * @return list<array{id: int, name: string, url: string, logo: ?string}>
     */
    public static function brandSwitcher(): array
    {
        if (! auth()->check()) {
            return [];
        }
        try {
            return Cache::remember('operator-menu.brands', 300, fn (): array => Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url'])
                ->map(fn (Brand $brand): array => ['id' => (int) $brand->id, 'name' => (string) $brand->name, 'url' => route('operator.brand', ['brand' => $brand->id], absolute: false), 'logo' => $brand->logo_url ?: null])
                ->values()->all());
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * The one sidebar entry of the current screen (so "Dijital varlıklar" and "Web siteleri" are never lit together on
     * /assets/website/5): an entry naming the current route wins, then the longest path prefix.
     *
     * @param  list<array{path: string, routes: list<string>}>  $items
     */
    public static function activeEntry(array $items, string $currentPath, ?string $routeName): ?string
    {
        if ($routeName !== null) {
            foreach ($items as $item) {
                if (in_array($routeName, $item['routes'], true)) {
                    return $item['path'];
                }
            }
        }
        $best = null;
        foreach ($items as $item) {
            $path = $item['path'];
            $matches = $currentPath === $path || ($path !== '/' && str_starts_with($currentPath, rtrim($path, '/').'/'));
            if ($matches && ($best === null || strlen($path) > strlen($best))) {
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * Tabs for the current screen when it belongs to a sidebar entry with children (W7): the entry itself first,
     * then its children. Null when the screen has no siblings.
     *
     * @return list<array{label: string, url: string, active: bool}>|null
     */
    public static function sectionTabs(?string $routeName = null): ?array
    {
        $routeName ??= request()->route()?->getName();
        if ($routeName === null) {
            return null;
        }
        foreach (DemoMenu::groups() as $group) {
            foreach ($group['items'] as $item) {
                $children = $item['children'] ?? [];
                $routes = [$item['route'], ...array_column($children, 'route')];
                if ($children === [] || ! in_array($routeName, $routes, true)) {
                    continue;
                }

                return array_map(fn (array $entry): array => [
                    'label' => $entry['label'],
                    'url' => route($entry['route'], absolute: false),
                    'active' => $entry['route'] === $routeName,
                ], [['label' => $item['label'], 'route' => $item['route']], ...$children]);
            }
        }

        return null;
    }

    public static function icon(string $key): string
    {
        return match ($key) {
            'dashboard' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
            'flow' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>',
            'portfolio' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
            'work' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="m3 6 1.5 1.5L7 5"/><path d="m3 12 1.5 1.5L7 11"/><path d="m3 18 1.5 1.5L7 17"/></svg>',
            'customers' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'website' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18z"/></svg>',
            'brands' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'assets' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
            'integrations' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'findings' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            'recommendations' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12c.5.5 1 1.5 1 2h6c0-.5.5-1.5 1-2a7 7 0 0 0-4-12z"/></svg>',
            'ads-advisor' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11l18-8-8 18-2-8-8-2z"/></svg>',
            'seo' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>',
            'tasks' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
            'archive' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/></svg>',
            'compliance' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>',
            'renewals' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><path d="M9 15l2 2 4-4"/></svg>',
            'activity' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'settings' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
            'files' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="8" y1="9" x2="10" y2="9"/></svg>',
            'prospects' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-2 2 2 2"/><path d="M17 13h5"/></svg>',
            'library' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'search' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/><path d="M8 9h6M8 12h4"/></svg>',
            'alerts' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
            'competitors' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M15 20c0-2 1.5-3.5 4-3.5"/></svg>',
            'map' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s7-7 7-12a7 7 0 0 0-14 0c0 5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>',
            'links' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
            'watch' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
            'ai' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="M19 15l.8 1.9 1.9.8-1.9.8L19 20.5l-.8-1.9-1.9-.8 1.9-.8z"/></svg>',
            'inbox' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/></svg>',
            'radar' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="M12 12l6-6"/></svg>',
            'chat' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
            'report' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/></svg>',
            'notes' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v12l-4 4H4z"/><path d="M16 20v-4h4"/><path d="M8 9h8M8 13h5"/></svg>',
            'wordpress' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M5 8l4 11 3-8 3 8 4-11"/></svg>',
            'brain-map' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8 7.5l3 8.5M16 7.5l-3 8.5M8.5 6h7"/></svg>',
            'brain-recs' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3z"/></svg>',
            'brain-methods' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5M4 19h16"/><path d="M7 15l4-4 3 3 5-6"/></svg>',
            'health' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
            'strategy' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14"/><path d="M4 19a2 2 0 0 0 2 2h14V17H6a2 2 0 0 0-2 2z"/><path d="M8 7h8M8 11h5"/></svg>',
            'content' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
            'trophy' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 21h8M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/></svg>',
            'scorecard' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></svg>',
            'calendar' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
            'finance' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>',
            'command' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/></svg>',
            'queue' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/><path d="m17 16 2 2 3-4"/></svg>',
            'data' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
            'brain-queue' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9l2 2 4-4M8 16h8"/></svg>',
            'chat' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.3L4 20z"/><path d="M9 10h6M9 13h4"/></svg>',
            default => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/></svg>',
        };
    }
}
