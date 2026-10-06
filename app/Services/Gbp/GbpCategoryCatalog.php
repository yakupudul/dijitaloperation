<?php

namespace App\Services\Gbp;

use App\Models\CoreIntegration;
use App\Services\ExternalWrites\GbpWriter;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Read-only Business Information API v1 calls behind "Kategori ve hizmetler": Google's own category list (search by
 * name, Turkish, Türkiye, with each category's predefined service types) and the location's live categories and
 * service items. Category searches are cached for a week.
 */
final class GbpCategoryCatalog
{
    public const string BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const int SERVICE_TYPES_MAX = 80;

    public function __construct(
        private readonly GoogleApiClient $google,
        private readonly GbpWriter $writer,
    ) {}

    /** @return array{0: CoreIntegration, 1: string} integration and the v1 name "locations/{id}" of the asset's bound location */
    public function location(int $assetId): array
    {
        [$integration, $parent] = $this->writer->location($assetId);

        return [$integration, 'locations/'.substr($parent, (int) strrpos($parent, '/') + 1)];
    }

    /**
     * The location's live categories and service items (as Google returns them).
     *
     * @return array{categories: array<string, mixed>, serviceItems: list<array<string, mixed>>}
     */
    public function current(CoreIntegration $integration, string $locationName): array
    {
        $response = $this->google->get($integration, self::BASE.$locationName, ['readMask' => 'categories,serviceItems'], 'google_business_profile');
        if (! $response->successful()) {
            throw new RuntimeException('İşletme Profili okunamadı: '.mb_substr((string) (data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status()), 0, 200));
        }

        return ['categories' => (array) ($response->json('categories') ?? []), 'serviceItems' => array_values((array) ($response->json('serviceItems') ?? []))];
    }

    /**
     * Google categories whose Turkish name matches the term (at most 8).
     *
     * @return list<array{id: string, name: string, service_types: list<array{id: string, name: string}>}>
     */
    public function search(CoreIntegration $integration, string $term): array
    {
        // Markdown, punctuation and brackets ("Ortodonti (diş teli)", "**Diş Kliniği**") make Google answer "invalid argument".
        $term = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $term) ?? '') ?? '');
        if ($term === '') {
            return [];
        }
        $key = 'gbp-category-search:'.md5(SeoText::fold($term));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        // The documented form first, then the quoted phrase, then the longest word alone (Google refuses some multi-word filters).
        $words = explode(' ', $term);
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $error = '';
        foreach (array_unique(['displayName='.$term, 'displayName="'.$term.'"', 'displayName='.$words[0]]) as $filter) {
            $response = $this->google->get($integration, self::BASE.'categories', [
                'regionCode' => 'TR', 'languageCode' => 'tr', 'view' => 'FULL', 'pageSize' => 8, 'filter' => $filter,
            ], 'google_business_profile');
            if ($response->successful()) {
                $found = array_map(self::category(...), array_slice((array) ($response->json('categories') ?? []), 0, 8));
                Cache::put($key, $found, now()->addWeek());

                return $found;
            }
            $error = (string) (data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status());
            if ($response->status() !== 400) {
                break;
            }
        }

        throw new RuntimeException('Google kategori listesi okunamadı: '.mb_substr($error, 0, 200));
    }

    /**
     * The given categories with their predefined service types (the profile's own categories); empty when Google
     * refuses the request.
     *
     * @param  list<string>  $ids  "categories/gcid:…"
     * @return array<string, array{id: string, name: string, service_types: list<array{id: string, name: string}>}>
     */
    public function batch(CoreIntegration $integration, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        // names is repeated (names=a&names=b), so the whole query is in the URL. Google has answered "invalid argument" to
        // the "categories/gcid:…" form on some accounts, so the bare GConcept id ("gcid:…") is tried next; when both fail
        // the profile's own categories are used without their predefined service types (the plan still works).
        $response = null;
        foreach ([$ids, array_map(fn (string $id): string => (string) preg_replace('~^categories/~', '', $id), $ids)] as $names) {
            $query = implode('&', array_map(fn (string $id): string => 'names='.rawurlencode($id), $names)).'&regionCode=TR&languageCode=tr&view=FULL';
            $response = $this->google->get($integration, self::BASE.'categories:batchGet?'.$query, [], 'google_business_profile');
            if ($response->successful()) {
                break;
            }
        }
        if (! $response->successful()) {
            Log::warning('GBP categories:batchGet failed', ['ids' => $ids, 'status' => $response->status(), 'error' => data_get($response->json(), 'error.message')]);

            return [];
        }
        $out = [];
        foreach ((array) ($response->json('categories') ?? []) as $row) {
            $category = self::category($row);
            if ($category['id'] !== '') {
                $out[$category['id']] = $category;
            }
        }

        return $out;
    }

    /** @return array{id: string, name: string, service_types: list<array{id: string, name: string}>} */
    private static function category(mixed $row): array
    {
        $types = [];
        foreach (array_slice((array) data_get($row, 'serviceTypes', []), 0, self::SERVICE_TYPES_MAX) as $type) {
            $id = (string) data_get($type, 'serviceTypeId', '');
            if ($id !== '') {
                $types[] = ['id' => $id, 'name' => (string) data_get($type, 'displayName', $id)];
            }
        }

        return ['id' => (string) data_get($row, 'name', ''), 'name' => (string) data_get($row, 'displayName', ''), 'service_types' => $types];
    }
}
