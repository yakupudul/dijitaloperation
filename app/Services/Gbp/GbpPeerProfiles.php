<?php

namespace App\Services\Gbp;

use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kategori ve hizmetler › "Aynı sektördeki işletmelerden getir" (yakup, 2026-10-06): the categories and service names
 * other Business Profiles of the same sector carry (profiles MoxDOP manages; their last collected data), counted by how
 * many profiles use them, without what this profile already has. The operator picks; the picked names go into the
 * plan boxes WITHOUT the other profiles' descriptions, so AI writes this brand's own (GbpProfilePlanner).
 */
final class GbpPeerProfiles
{
    public const int CATEGORIES_MAX = 20;

    public const int SERVICES_MAX = 120;

    public function __construct(
        private readonly GbpDesk $desk,
        private readonly GbpDailyWorkspace $daily,
    ) {}

    /**
     * @return array{peers: int, brands: list<string>, categories: list<array{key: string, name: string, count: int}>,
     *     services: list<array{key: string, name: string, category: string, count: int}>}
     */
    public function collect(DigitalAsset $asset): array
    {
        $sectorId = $asset->loadMissing('brand')->brand?->sector_id;
        if ($sectorId === null) {
            throw new RuntimeException('Markanın sektörü seçili değil; önce markaya sektör atayın.');
        }
        $peers = $this->desk->locations()->filter(fn (DigitalAsset $l): bool => (int) $l->id !== (int) $asset->id && (int) $l->brand?->sector_id === (int) $sectorId);
        $resources = $this->daily->resourceIds([(int) $asset->id, ...$peers->pluck('id')->map(fn ($id): int => (int) $id)->all()]);
        $own = $this->profile($resources[(int) $asset->id] ?? null);
        $ownCategories = array_flip(array_map(fn (string $n): string => SeoText::fold($n), $own['categories']));
        $ownServices = array_flip(array_map(fn (array $s): string => SeoText::fold($s['name']), $own['services']));

        $categories = [];
        $services = [];
        $used = [];
        foreach ($peers as $peer) {
            $profile = $this->profile($resources[(int) $peer->id] ?? null);
            if ($profile['categories'] === [] && $profile['services'] === []) {
                continue;
            }
            $used[(int) $peer->id] = (string) $peer->brand?->name;
            foreach (array_unique(array_map(fn (string $n): string => SeoText::fold($n), $profile['categories'])) as $i => $folded) {
                if (! isset($ownCategories[$folded])) {
                    $categories[$folded] ??= ['key' => 'c'.substr(md5($folded), 0, 10), 'name' => $profile['categories'][$i], 'count' => 0];
                    $categories[$folded]['count']++;
                }
            }
            $seen = [];
            foreach ($profile['services'] as $service) {
                $folded = SeoText::fold($service['name']);
                if ($folded === '' || isset($ownServices[$folded]) || isset($seen[$folded])) {
                    continue;
                }
                $seen[$folded] = true;
                $services[$folded] ??= ['key' => 's'.substr(md5($folded), 0, 10), 'name' => $service['name'], 'category' => $service['category'], 'count' => 0];
                $services[$folded]['count']++;
                if ($services[$folded]['category'] === '' && $service['category'] !== '') {
                    $services[$folded]['category'] = $service['category'];
                }
            }
        }
        $order = fn (array $a, array $b): int => [$b['count'], mb_strtolower($a['name'])] <=> [$a['count'], mb_strtolower($b['name'])];
        usort($categories, $order);
        usort($services, $order);

        return ['peers' => count($used), 'brands' => array_values(array_unique(array_filter($used))), 'categories' => array_slice($categories, 0, self::CATEGORIES_MAX),
            'services' => array_slice($services, 0, self::SERVICES_MAX)];
    }

    /**
     * The picked rows as the plan boxes' text: category lines, and service names under a heading only when that heading
     * is a category the profile has or one that is being added (a heading is itself read as a category request).
     *
     * @param  array{categories: list<array{key: string, name: string, count: int}>, services: list<array{key: string, name: string, category: string, count: int}>}  $found
     * @param  list<string>  $picked  keys
     * @param  list<string>  $profileCategories  names the profile already has
     * @return array{categories: list<string>, services: string}
     */
    public static function boxes(array $found, array $picked, array $profileCategories): array
    {
        $picked = array_flip($picked);
        $categories = array_values(array_map(fn (array $c): string => $c['name'], array_filter($found['categories'], fn (array $c): bool => isset($picked[$c['key']]))));
        $headings = array_flip(array_map(fn (string $n): string => SeoText::fold($n), [...$profileCategories, ...$categories]));
        $grouped = [];
        foreach ($found['services'] as $service) {
            if (isset($picked[$service['key']])) {
                $heading = isset($headings[SeoText::fold($service['category'])]) ? $service['category'] : '';
                $grouped[$heading][] = $service['name'];
            }
        }
        ksort($grouped);
        $text = [];
        foreach ($grouped as $heading => $names) {
            $text[] = ($heading !== '' ? '**'.$heading."**\n" : '').implode("\n", $names);
        }

        return ['categories' => $categories, 'services' => implode("\n\n", $text)];
    }

    /**
     * Category names and service names (with the category they sit under) of one profile's last collected data.
     *
     * @return array{categories: list<string>, services: list<array{name: string, category: string}>}
     */
    public function profile(?int $resourceId): array
    {
        if ($resourceId === null) {
            return ['categories' => [], 'services' => []];
        }
        $location = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first(['primary_category', 'additional_categories']);
        $byId = [];
        $categories = [];
        if ($location !== null) {
            if (trim((string) $location->primary_category) !== '' && ! str_starts_with((string) $location->primary_category, 'categories/')) {
                $categories[] = trim((string) $location->primary_category);
            }
            $decoded = GoogleAdsAdvisorInputCollector::decode($location->additional_categories);
            $list = array_is_list($decoded) ? $decoded : array_merge(isset($decoded['primaryCategory']) ? [$decoded['primaryCategory']] : [], (array) ($decoded['additionalCategories'] ?? []));
            foreach ($list as $category) {
                $name = trim((string) data_get($category, 'displayName', ''));
                if ($name !== '') {
                    $categories[] = $name;
                    $byId[(string) preg_replace('~^categories/~', '', (string) data_get($category, 'name', ''))] = $name;
                }
            }
        }
        $row = DB::table('gbp_service_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first(['service_items']);
        $services = [];
        foreach ((array) ($row !== null ? GoogleAdsAdvisorInputCollector::decode($row->service_items) : []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) (data_get($item, 'freeFormServiceItem.label.displayName')
                ?? str_replace(['job_type_id:', '_'], ['', ' '], (string) data_get($item, 'structuredServiceItem.serviceTypeId', ''))));
            $categoryId = (string) preg_replace('~^categories/~', '', (string) (data_get($item, 'freeFormServiceItem.category') ?? data_get($item, 'structuredServiceItem.category', '')));
            if ($name !== '') {
                $services[] = ['name' => mb_substr($name, 0, GbpProfilePlanner::NAME_MAX), 'category' => $byId[$categoryId] ?? ''];
            }
        }

        return ['categories' => array_values(array_unique($categories)), 'services' => $services];
    }
}
