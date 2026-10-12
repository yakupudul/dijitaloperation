<?php

namespace App\Services\Gbp;

use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        private readonly GbpCategoryCatalog $catalog,
    ) {}

    /**
     * Category names come from each profile's last collected data in the language Google answered for that profile
     * (an English-language profile gives "Dentist", "Paediatric Dentist"), so categories are counted by Google's
     * category id and every name is shown with Google's Turkish name of that id (yakup, 2026-10-12: English categories
     * next to Turkish ones, one category twice). Without an answer from Google the collected names stay.
     *
     * @return array{peers: int, brands: list<string>, categories: list<array{key: string, name: string, count: int}>,
     *     services: list<array{key: string, name: string, category: string, count: int}>, own_categories: list<string>}
     */
    public function collect(DigitalAsset $asset): array
    {
        $sectorId = $asset->loadMissing('brand')->brand?->sector_id;
        if ($sectorId === null) {
            throw new RuntimeException('Markanın sektörü seçili değil; önce markaya sektör atayın.');
        }
        $peers = $this->desk->locations()->filter(fn (DigitalAsset $l): bool => (int) $l->id !== (int) $asset->id && (int) $l->brand?->sector_id === (int) $sectorId);
        $resources = $this->daily->resourceIds([(int) $asset->id, ...$peers->pluck('id')->map(fn ($id): int => (int) $id)->all()]);
        $own = $this->entries($resources[(int) $asset->id] ?? null);
        $profiles = [];
        foreach ($peers as $peer) {
            $profile = $this->entries($resources[(int) $peer->id] ?? null);
            if ($profile['categories'] !== [] || $profile['services'] !== []) {
                $profiles[(int) $peer->id] = $profile;
            }
        }
        $turkish = $this->turkish($asset, [$own, ...array_values($profiles)]);
        $own = self::translate($own, $turkish);
        $ownCategories = [];
        foreach ($own['categories'] as $category) {
            $ownCategories[self::categoryKey($category)] = true;
            $ownCategories[SeoText::fold($category['name'])] = true;
        }
        $ownServices = array_flip(array_map(fn (array $s): string => SeoText::fold($s['name']), $own['services']));

        $categories = [];
        $services = [];
        $used = [];
        foreach ($peers as $peer) {
            if (! isset($profiles[(int) $peer->id])) {
                continue;
            }
            $profile = self::translate($profiles[(int) $peer->id], $turkish);
            $used[(int) $peer->id] = (string) $peer->brand?->name;
            $seen = [];
            foreach ($profile['categories'] as $category) {
                $folded = SeoText::fold($category['name']);
                $key = self::categoryKey($category);
                if (isset($seen[$key]) || isset($seen[$folded]) || isset($ownCategories[$key]) || isset($ownCategories[$folded])) {
                    continue;
                }
                $seen[$key] = $seen[$folded] = true;
                // One row per Turkish name: two ids Google names alike are one category to the operator.
                $categories[$folded] ??= ['key' => 'c'.substr(md5($folded), 0, 10), 'name' => $category['name'], 'count' => 0];
                $categories[$folded]['count']++;
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
            'services' => array_slice($services, 0, self::SERVICES_MAX), 'own_categories' => array_values(array_unique(array_column($own['categories'], 'name')))];
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
        $entries = $this->entries($resourceId);

        return ['categories' => array_values(array_unique(array_column($entries['categories'], 'name'))),
            'services' => array_map(fn (array $s): array => ['name' => $s['name'], 'category' => $s['category']], $entries['services'])];
    }

    /**
     * One profile's last collected categories and services with Google's ids ("gcid:…", "job_type_id:…"; '' when unknown).
     *
     * @return array{categories: list<array{id: string, name: string}>, services: list<array{name: string, category: string, category_id: string, type_id: string}>}
     */
    private function entries(?int $resourceId): array
    {
        if ($resourceId === null) {
            return ['categories' => [], 'services' => []];
        }
        $location = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first(['primary_category', 'additional_categories']);
        $byId = [];
        $categories = [];
        if ($location !== null) {
            if (trim((string) $location->primary_category) !== '' && ! str_starts_with((string) $location->primary_category, 'categories/')) {
                $categories[] = ['id' => '', 'name' => trim((string) $location->primary_category)];
            }
            $decoded = GoogleAdsAdvisorInputCollector::decode($location->additional_categories);
            $list = array_is_list($decoded) ? $decoded : array_merge(isset($decoded['primaryCategory']) ? [$decoded['primaryCategory']] : [], (array) ($decoded['additionalCategories'] ?? []));
            foreach ($list as $category) {
                $name = trim((string) data_get($category, 'displayName', ''));
                $id = self::bareId((string) data_get($category, 'name', ''));
                if ($name !== '') {
                    // The primary category column holds the same name as the decoded primary category: keep one, with its id.
                    $categories = array_values(array_filter($categories, fn (array $c): bool => $c['id'] !== '' || SeoText::fold($c['name']) !== SeoText::fold($name)));
                    $categories[] = ['id' => $id, 'name' => $name];
                    $byId[$id] = $name;
                }
            }
        }
        $row = DB::table('gbp_service_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first(['service_items']);
        $services = [];
        foreach ((array) ($row !== null ? GoogleAdsAdvisorInputCollector::decode($row->service_items) : []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $typeId = (string) data_get($item, 'structuredServiceItem.serviceTypeId', '');
            $name = trim((string) (data_get($item, 'freeFormServiceItem.label.displayName') ?? str_replace(['job_type_id:', '_'], ['', ' '], $typeId)));
            $categoryId = self::bareId((string) (data_get($item, 'freeFormServiceItem.category') ?? data_get($item, 'structuredServiceItem.category', '')));
            if ($name !== '') {
                $services[] = ['name' => mb_substr($name, 0, GbpProfilePlanner::NAME_MAX), 'category' => $byId[$categoryId] ?? '', 'category_id' => $categoryId,
                    'type_id' => data_get($item, 'freeFormServiceItem') === null ? $typeId : ''];
            }
        }

        return ['categories' => $categories, 'services' => $services];
    }

    /**
     * Google's Turkish names of every category the profiles carry, read with this profile's Google connection.
     *
     * @param  list<array{categories: list<array{id: string, name: string}>, services: list<array{category_id: string}>}>  $profiles
     * @return array<string, array{name: string, service_types: array<string, string>}>
     */
    private function turkish(DigitalAsset $asset, array $profiles): array
    {
        $ids = [];
        foreach ($profiles as $profile) {
            foreach ($profile['categories'] as $category) {
                $ids[] = $category['id'];
            }
            foreach ($profile['services'] as $service) {
                $ids[] = $service['category_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        try {
            [$integration] = $this->catalog->location((int) $asset->id);

            return $this->catalog->turkishNames($integration, $ids);
        } catch (Throwable $exception) {
            Log::info('GBP peer categories kept as collected (no Turkish names)', ['asset_id' => $asset->id, 'error' => mb_substr($exception->getMessage(), 0, 200)]);

            return [];
        }
    }

    /**
     * @param  array{categories: list<array{id: string, name: string}>, services: list<array{name: string, category: string, category_id: string, type_id: string}>}  $profile
     * @param  array<string, array{name: string, service_types: array<string, string>}>  $turkish
     * @return array{categories: list<array{id: string, name: string}>, services: list<array{name: string, category: string, category_id: string, type_id: string}>}
     */
    private static function translate(array $profile, array $turkish): array
    {
        foreach ($profile['categories'] as $i => $category) {
            $profile['categories'][$i]['name'] = $turkish[$category['id']]['name'] ?? $category['name'];
        }
        foreach ($profile['services'] as $i => $service) {
            $known = $turkish[$service['category_id']] ?? null;
            if ($known === null && $service['type_id'] !== '') {
                // A predefined service carries no category of its own: the category that offers its type is its heading.
                $known = collect($turkish)->first(fn (array $c): bool => isset($c['service_types'][$service['type_id']]));
            }
            if ($known !== null) {
                $profile['services'][$i]['category'] = $known['name'];
                if ($service['type_id'] !== '' && isset($known['service_types'][$service['type_id']])) {
                    $profile['services'][$i]['name'] = $known['service_types'][$service['type_id']];
                }
            }
        }

        return $profile;
    }

    /** @param  array{id: string, name: string}  $category */
    private static function categoryKey(array $category): string
    {
        return $category['id'] !== '' ? 'id:'.$category['id'] : 'name:'.SeoText::fold($category['name']);
    }

    private static function bareId(string $id): string
    {
        return (string) preg_replace('~^categories/~', '', trim($id));
    }
}
