<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Services\Gbp\GbpProfilePlanner;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Services\SeoTasks\SeoText;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Business Profile writes. ADR-073: a review reply (undo restores the previous reply or deletes it) and a local post
 * (undo deletes it). ADR-077: categories and service items the Admin chose from a "Kategori ve hizmetler" plan are
 * added to what the profile has now (read just before the write; nothing existing is removed or changed, the primary
 * category stays); undo removes exactly what was added. ADR-079: the description, special hours (only the dates sent;
 * other dates stay) and the website link, each with the previous value kept for undo, and a photo from an https
 * address (undo deletes it). ADR-080: regular hours, primary phone, primary category, yes / no attributes, an
 * appointment link and a video, each undoable. Name, address and the rest are never changed.
 */
final class GbpWriter
{
    private const string BASE = 'https://mybusiness.googleapis.com/v4/';

    private const string V1 = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const string PLACE_ACTIONS = 'https://mybusinessplaceactions.googleapis.com/v1/';

    public const int ADDITIONAL_MAX = 9;

    public function __construct(private readonly GoogleApiClient $google) {}

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $payload = (array) $action->request_payload;

        if ($action->action === ExternalWriteAction::ACTION_PROFILE_UPDATE) {
            Cache::forget(GbpProfilePlanner::liveKey((int) $action->digital_asset_id));

            return $this->addToProfile($integration, 'locations/'.substr($parent, (int) strrpos($parent, '/') + 1), $payload);
        }
        if ($action->action === ExternalWriteAction::ACTION_PROFILE_FIELDS) {
            return $this->writeFields($integration, 'locations/'.substr($parent, (int) strrpos($parent, '/') + 1), (array) ($payload['fields'] ?? []));
        }
        if ($action->action === ExternalWriteAction::ACTION_MEDIA_UPLOAD) {
            $created = $this->call($integration, 'post', self::BASE.$parent.'/media', ['mediaFormat' => ($payload['media_format'] ?? 'PHOTO') === 'VIDEO' ? 'VIDEO' : 'PHOTO',
                'locationAssociation' => ['category' => (string) ($payload['category'] ?? 'ADDITIONAL')], 'sourceUrl' => (string) $payload['source_url']]);
            $name = (string) ($created['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Medya yüklendi ama adı dönmedi.');
            }

            return ['status' => 'succeeded', 'media' => $name, 'google_url' => $created['googleUrl'] ?? null];
        }

        if ($action->action === ExternalWriteAction::ACTION_REVIEW_REPLY) {
            $review = GbpReview::query()->findOrFail((int) $payload['review_id']);
            $previous = is_array($review->review_reply) ? (string) ($review->review_reply['comment'] ?? '') : '';
            $this->call($integration, 'put', self::BASE.$parent.'/reviews/'.$review->review_id.'/reply', ['comment' => (string) $payload['comment']]);
            $review->forceFill(['review_reply' => ['comment' => (string) $payload['comment'], 'updateTime' => now()->toIso8601String()]])->save();

            return ['status' => 'succeeded', 'review_id' => $review->review_id, 'previous_reply' => $previous];
        }

        $body = ['languageCode' => 'tr', 'topicType' => 'STANDARD', 'summary' => (string) $payload['summary']];
        if (filled($payload['url'] ?? null)) {
            $type = (string) ($payload['action_type'] ?? 'LEARN_MORE');
            // CALL dials the profile's phone; Google rejects a URL on it.
            $body['callToAction'] = $type === 'CALL' ? ['actionType' => 'CALL'] : ['actionType' => $type, 'url' => (string) $payload['url']];
        }
        $note = null;
        $image = (string) ($payload['image_url'] ?? '');
        if ($image !== '') {
            // Google fetches post photos itself and takes only JPG / PNG; a WebP or other image ends in "Internal error".
            if (preg_match('~\.(?:jpe?g|png)(?:\?\S*)?$~i', $image) === 1) {
                $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $image]];
            } else {
                $note = 'Görsel JPG / PNG olmadığı için Google kabul etmez; gönderi görselsiz yayımlandı.';
            }
        }
        $url = self::BASE.$parent.'/localPosts';
        try {
            $created = $this->call($integration, 'post', $url, $body);
        } catch (RuntimeException $exception) {
            // "Internal error encountered" (HTTP 500): Google could not fetch the photo, or a passing fault. One more try,
            // without the photo when there is one, so an approved post still goes out.
            if (! str_contains($exception->getMessage(), 'Internal error') && ! str_contains($exception->getMessage(), 'HTTP 5')) {
                throw $exception;
            }
            if (isset($body['media'])) {
                unset($body['media']);
                $note = 'Google görseli alamadı (iç hata); gönderi görselsiz yayımlandı.';
            }
            try {
                $created = $this->call($integration, 'post', $url, $body);
            } catch (RuntimeException) {
                throw new RuntimeException('İşletme Profili: Google gönderiyi kabul ederken iç hata verdi (iki deneme). Genellikle geçicidir; birkaç dakika sonra yeniden deneyin.');
            }
        }
        $name = (string) ($created['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Gönderi oluşturuldu ama adı dönmedi.');
        }

        return array_filter(['status' => 'succeeded', 'post' => $name, 'search_url' => $created['searchUrl'] ?? null, 'note' => $note], fn ($v): bool => $v !== null);
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $result = (array) $action->result;
        if ($action->action === ExternalWriteAction::ACTION_PROFILE_UPDATE) {
            Cache::forget(GbpProfilePlanner::liveKey((int) $action->digital_asset_id));

            return $this->removeFromProfile($integration, $result);
        }
        if ($action->action === ExternalWriteAction::ACTION_PROFILE_FIELDS) {
            return $this->restoreFields($integration, $result);
        }
        if ($action->action === ExternalWriteAction::ACTION_MEDIA_UPLOAD) {
            $name = (string) ($result['media'] ?? '');
            if (preg_match('#^accounts/[^/]+/locations/[^/]+/media/[^/]+$#', $name) !== 1) {
                throw new RuntimeException('Silinecek fotoğraf bilinmiyor.');
            }
            $this->call($integration, 'delete', self::BASE.$name);

            return ['deleted' => $name];
        }
        if ($action->action === ExternalWriteAction::ACTION_REVIEW_REPLY) {
            $review = GbpReview::query()->where('review_id', (string) $result['review_id'])->first();
            $previous = (string) ($result['previous_reply'] ?? '');
            $url = self::BASE.$parent.'/reviews/'.$result['review_id'].'/reply';
            $previous !== '' ? $this->call($integration, 'put', $url, ['comment' => $previous]) : $this->call($integration, 'delete', $url);
            $review?->forceFill(['review_reply' => $previous !== '' ? ['comment' => $previous] : null])->save();

            return ['restored' => $previous !== '' ? 'previous_reply' : 'no_reply'];
        }
        $name = (string) ($result['post'] ?? '');
        $this->call($integration, 'delete', self::BASE.$name);

        return ['deleted' => $name];
    }

    /**
     * ADR-077: adds the chosen categories (after the existing additional ones) and service items (after the existing
     * ones) to the live profile. Already present items are skipped; a services failure after the categories were
     * written leaves the action "partial" (undo removes the categories).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addToProfile(CoreIntegration $integration, string $location, array $payload): array
    {
        $current = $this->profile($integration, $location);
        $primary = (string) data_get($current, 'categories.primaryCategory.name', '');
        if ($primary === '') {
            throw new RuntimeException('Profilin birincil kategorisi yok; önce Google’da birincil kategori seçin.');
        }
        $additional = self::categoryNames($current);
        $addCategories = [];
        foreach ((array) ($payload['categories'] ?? []) as $category) {
            $id = (string) data_get($category, 'id', '');
            if (str_starts_with($id, 'categories/') && $id !== $primary && ! in_array($id, $additional, true) && ! in_array($id, $addCategories, true)) {
                $addCategories[] = $id;
            }
        }
        if (count($additional) + count($addCategories) > self::ADDITIONAL_MAX) {
            throw new RuntimeException('İşletme Profili en çok '.self::ADDITIONAL_MAX.' ek kategori alır; profilde '.count($additional).' var, '.count($addCategories).' eklenmek istendi.');
        }
        $items = array_values((array) ($current['serviceItems'] ?? []));
        $addServices = [];
        foreach ((array) ($payload['services'] ?? []) as $service) {
            $item = self::serviceItem((array) $service);
            if ($item !== null && ! self::containsItem([...$items, ...$addServices], $item)) {
                $addServices[] = $item;
            }
        }
        if ($addCategories === [] && $addServices === []) {
            throw new RuntimeException('Seçilenlerin hepsi profilde zaten var.');
        }
        $result = ['status' => 'succeeded', 'location' => $location, 'added_categories' => [], 'added_services' => [],
            'before' => ['additional_categories' => count($additional), 'services' => count($items)]];
        if ($addCategories !== []) {
            $this->call($integration, 'patch', self::V1.$location.'?updateMask=categories', ['categories' => [
                'primaryCategory' => ['name' => $primary],
                'additionalCategories' => array_map(fn (string $id): array => ['name' => $id], [...$additional, ...$addCategories]),
            ]]);
            $result['added_categories'] = $addCategories;
        }
        if ($addServices !== []) {
            try {
                $this->call($integration, 'patch', self::V1.$location.'?updateMask=serviceItems', ['serviceItems' => [...$items, ...$addServices]]);
                $result['added_services'] = $addServices;
            } catch (Throwable $exception) {
                if ($result['added_categories'] === []) {
                    throw $exception;
                }
                $result['status'] = 'partial';
                $result['error'] = 'Kategoriler eklendi, hizmetler eklenemedi: '.$exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * ADR-077 undo: removes the added categories and service items from what the profile has now; anything changed on
     * Google meanwhile stays.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function removeFromProfile(CoreIntegration $integration, array $result): array
    {
        $location = (string) ($result['location'] ?? '');
        if (preg_match('#^locations/[^/?]+$#', $location) !== 1) {
            throw new RuntimeException('Geri alınacak konum bilinmiyor.');
        }
        $current = $this->profile($integration, $location);
        $removedCategories = 0;
        $added = (array) ($result['added_categories'] ?? []);
        if ($added !== []) {
            $additional = self::categoryNames($current);
            $keep = array_values(array_diff($additional, $added));
            $removedCategories = count($additional) - count($keep);
            if ($removedCategories > 0) {
                $this->call($integration, 'patch', self::V1.$location.'?updateMask=categories', ['categories' => [
                    'primaryCategory' => ['name' => (string) data_get($current, 'categories.primaryCategory.name')],
                    'additionalCategories' => array_map(fn (string $id): array => ['name' => $id], $keep),
                ]]);
            }
        }
        $removedServices = 0;
        $addedServices = array_values((array) ($result['added_services'] ?? []));
        if ($addedServices !== []) {
            $items = array_values((array) ($current['serviceItems'] ?? []));
            $keep = array_values(array_filter($items, fn (mixed $item): bool => ! self::containsItem($addedServices, (array) $item)));
            $removedServices = count($items) - count($keep);
            if ($removedServices > 0) {
                $this->call($integration, 'patch', self::V1.$location.'?updateMask=serviceItems', ['serviceItems' => $keep]);
            }
        }

        return ['removed_categories' => $removedCategories, 'removed_services' => $removedServices];
    }

    /**
     * ADR-079 / ADR-080: writes the given fields one by one after reading the live values — description, special hours
     * (only the dates sent change), website link, regular hours, primary phone (additional phones stay), primary
     * category (the previous primary stays as an additional category), yes / no attributes (only those sent) and an
     * appointment link (added next to existing links). A later field failing after an earlier one was written leaves
     * the action "partial".
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function writeFields(CoreIntegration $integration, string $location, array $fields): array
    {
        $current = $this->fields($integration, $location);
        $result = ['status' => 'succeeded', 'location' => $location, 'written' => [], 'before' => []];
        $patch = fn (string $mask, array $body): array => $this->call($integration, 'patch', self::V1.$location.'?updateMask='.$mask, $body);
        /** @var list<array{0: string, 1: callable(): array{0: mixed, 1: mixed}}> $steps */
        $steps = [];
        if (array_key_exists('description', $fields)) {
            $text = trim((string) $fields['description']);
            $steps[] = ['description', function () use ($patch, $text, $current): array {
                $patch('profile.description', ['profile' => ['description' => $text]]);

                return [$text, (string) data_get($current, 'profile.description', '')];
            }];
        }
        if (array_key_exists('website_uri', $fields)) {
            $uri = trim((string) $fields['website_uri']);
            $steps[] = ['website_uri', function () use ($patch, $uri, $current): array {
                $patch('websiteUri', ['websiteUri' => $uri]);

                return [$uri, (string) ($current['websiteUri'] ?? '')];
            }];
        }
        if (array_key_exists('special_hours', $fields)) {
            $periods = array_values(array_filter(array_map(fn (mixed $p): ?array => is_array($p) ? self::specialPeriod($p) : null, (array) $fields['special_hours'])));
            if ($periods === []) {
                throw new RuntimeException('Gönderilecek özel gün yok.');
            }
            $steps[] = ['special_hours', function () use ($patch, $periods, $current): array {
                $dates = array_map(fn (array $p): string => self::periodDate($p), $periods);
                $existing = array_values((array) data_get($current, 'specialHours.specialHourPeriods', []));
                $kept = array_values(array_filter($existing, fn (mixed $p): bool => ! in_array(self::periodDate((array) $p), $dates, true)));
                $replaced = array_values(array_filter($existing, fn (mixed $p): bool => in_array(self::periodDate((array) $p), $dates, true)));
                $patch('specialHours', ['specialHours' => ['specialHourPeriods' => [...$kept, ...$periods]]]);

                return [$periods, $replaced];
            }];
        }
        if (array_key_exists('regular_hours', $fields)) {
            $periods = self::regularPeriods((array) $fields['regular_hours']);
            if ($periods === []) {
                throw new RuntimeException('Gönderilecek çalışma saati yok.');
            }
            $steps[] = ['regular_hours', function () use ($patch, $periods, $current): array {
                $patch('regularHours', ['regularHours' => ['periods' => $periods]]);

                return [$periods, array_values((array) data_get($current, 'regularHours.periods', []))];
            }];
        }
        if (array_key_exists('phone', $fields)) {
            $phone = trim((string) $fields['phone']);
            $steps[] = ['phone', function () use ($patch, $phone, $current): array {
                $before = (array) ($current['phoneNumbers'] ?? []);
                $patch('phoneNumbers', ['phoneNumbers' => array_filter(['primaryPhone' => $phone, 'additionalPhones' => $before['additionalPhones'] ?? null])]);

                return [$phone, $before];
            }];
        }
        if (array_key_exists('primary_category', $fields)) {
            $id = (string) data_get($fields, 'primary_category.id', '');
            $steps[] = ['primary_category', function () use ($patch, $id, $current): array {
                $old = (string) data_get($current, 'categories.primaryCategory.name', '');
                $additional = self::categoryNames($current);
                if ($old === $id) {
                    throw new RuntimeException('Bu kategori zaten birincil.');
                }
                $next = array_values(array_filter($additional, fn (string $c): bool => $c !== $id));
                if ($old !== '' && count($next) < self::ADDITIONAL_MAX) {
                    array_unshift($next, $old);
                }
                $patch('categories', ['categories' => ['primaryCategory' => ['name' => $id],
                    'additionalCategories' => array_map(fn (string $c): array => ['name' => $c], $next)]]);

                return [$id, ['primary' => $old, 'additional' => $additional]];
            }];
        }
        if (array_key_exists('attributes', $fields)) {
            $attributes = array_values((array) $fields['attributes']);
            $steps[] = ['attributes', function () use ($integration, $location, $attributes): array {
                $live = $this->attributes($integration, $location);
                $before = [];
                foreach ($attributes as $attribute) {
                    $before[(string) $attribute['name']] = $live[(string) $attribute['name']] ?? null;
                }
                $this->writeAttributes($integration, $location, array_map(fn (array $a): array => ['name' => (string) $a['name'], 'values' => [(bool) $a['value']]], $attributes), array_keys($before));

                return [$attributes, $before];
            }];
        }
        if (array_key_exists('appointment_url', $fields)) {
            $uri = trim((string) $fields['appointment_url']);
            $steps[] = ['appointment_url', function () use ($integration, $location, $uri): array {
                $created = $this->call($integration, 'post', self::PLACE_ACTIONS.$location.'/placeActionLinks', ['uri' => $uri, 'placeActionType' => 'APPOINTMENT']);
                $name = (string) ($created['name'] ?? '');
                if (preg_match('#^locations/[^/]+/placeActionLinks/[^/]+$#', $name) !== 1) {
                    throw new RuntimeException('Randevu bağlantısı eklendi ama adı dönmedi.');
                }

                return [['uri' => $uri, 'link' => $name], null];
            }];
        }
        if ($steps === []) {
            throw new RuntimeException('Gönderilecek alan yok.');
        }
        foreach ($steps as [$key, $write]) {
            try {
                [$written, $before] = $write();
                $result['written'][$key] = $written;
                $result['before'][$key] = $before;
            } catch (Throwable $exception) {
                if ($result['written'] === []) {
                    throw $exception;
                }
                $result['status'] = 'partial';
                $result['error'] = mb_substr($exception->getMessage(), 0, 300);

                break;
            }
        }

        return $result;
    }

    /**
     * ADR-079 / ADR-080 undo: each written field goes back to its previous value when it still holds what MoxDOP wrote
     * (a later change on Google stays); special hours: the dates written are replaced by what those dates had before;
     * attributes: only those still holding the written value go back; the appointment link added is removed.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function restoreFields(CoreIntegration $integration, array $result): array
    {
        $location = (string) ($result['location'] ?? '');
        if (preg_match('#^locations/[^/?]+$#', $location) !== 1) {
            throw new RuntimeException('Geri alınacak konum bilinmiyor.');
        }
        $current = $this->fields($integration, $location);
        $written = (array) ($result['written'] ?? []);
        $before = (array) ($result['before'] ?? []);
        $patch = fn (string $mask, array $body): array => $this->call($integration, 'patch', self::V1.$location.'?updateMask='.$mask, $body);
        $restored = [];
        $kept = [];
        $back = function (string $key, bool $unchanged, callable $restore) use (&$restored, &$kept): void {
            if ($unchanged) {
                $restore();
                $restored[] = $key;
            } else {
                $kept[] = $key;
            }
        };
        if (array_key_exists('description', $written)) {
            $back('description', trim((string) data_get($current, 'profile.description', '')) === trim((string) $written['description']),
                fn () => $patch('profile.description', ['profile' => ['description' => (string) ($before['description'] ?? '')]]));
        }
        if (array_key_exists('website_uri', $written)) {
            $back('website_uri', trim((string) ($current['websiteUri'] ?? '')) === trim((string) $written['website_uri']),
                fn () => $patch('websiteUri', ['websiteUri' => (string) ($before['website_uri'] ?? '')]));
        }
        if (array_key_exists('special_hours', $written)) {
            $dates = array_map(fn (mixed $p): string => self::periodDate((array) $p), (array) $written['special_hours']);
            $existing = array_values((array) data_get($current, 'specialHours.specialHourPeriods', []));
            $others = array_values(array_filter($existing, fn (mixed $p): bool => ! in_array(self::periodDate((array) $p), $dates, true)));
            $back('special_hours', true, fn () => $patch('specialHours', ['specialHours' => ['specialHourPeriods' => [...$others, ...array_values((array) ($before['special_hours'] ?? []))]]]));
        }
        if (array_key_exists('regular_hours', $written)) {
            $previous = array_values((array) ($before['regular_hours'] ?? []));
            $back('regular_hours', self::hoursKey((array) data_get($current, 'regularHours.periods', [])) === self::hoursKey((array) $written['regular_hours']),
                fn () => $patch('regularHours', ['regularHours' => $previous === [] ? new \stdClass : ['periods' => $previous]]));
        }
        if (array_key_exists('phone', $written)) {
            $digits = fn (string $p): string => (string) preg_replace('/\D+/', '', $p);
            $back('phone', $digits((string) data_get($current, 'phoneNumbers.primaryPhone', '')) === $digits((string) $written['phone']),
                fn () => $patch('phoneNumbers', ['phoneNumbers' => (array) ($before['phone'] ?? []) ?: new \stdClass]));
        }
        if (array_key_exists('primary_category', $written)) {
            $previous = (array) ($before['primary_category'] ?? []);
            $back('primary_category', (string) data_get($current, 'categories.primaryCategory.name', '') === (string) $written['primary_category'] && filled($previous['primary'] ?? null),
                fn () => $patch('categories', ['categories' => ['primaryCategory' => ['name' => (string) $previous['primary']],
                    'additionalCategories' => array_map(fn (string $c): array => ['name' => $c], array_values((array) ($previous['additional'] ?? [])))]]));
        }
        if (array_key_exists('attributes', $written)) {
            $live = $this->attributes($integration, $location);
            $names = [];
            $values = [];
            foreach ((array) $written['attributes'] as $attribute) {
                $name = (string) $attribute['name'];
                if (($live[$name] ?? null) !== [(bool) $attribute['value']]) {
                    $kept[] = $name;

                    continue;
                }
                $names[] = $name;
                $previous = ((array) ($before['attributes'] ?? []))[$name] ?? null;
                if (is_array($previous)) {
                    $values[] = ['name' => $name, 'values' => $previous];
                }
            }
            if ($names !== []) {
                $this->writeAttributes($integration, $location, $values, $names);
                $restored[] = 'attributes';
            }
        }
        if (array_key_exists('appointment_url', $written)) {
            $link = (string) data_get($written, 'appointment_url.link', '');
            if (preg_match('#^locations/[^/]+/placeActionLinks/[^/]+$#', $link) !== 1) {
                throw new RuntimeException('Silinecek randevu bağlantısı bilinmiyor.');
            }
            $this->call($integration, 'delete', self::PLACE_ACTIONS.$link);
            $restored[] = 'appointment_url';
        }

        return ['restored' => $restored, 'changed_since' => $kept];
    }

    /**
     * Day rows (`{day: MONDAY, open: "09:00", close: "18:00"}`; a day without a row is closed) as Google's periods. A
     * close time of 24:00 or earlier than the opening closes the next day.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function regularPeriods(array $rows): array
    {
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
        $time = static fn (string $t): ?array => preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $t, $m) === 1 && ! ($m[1] === '24' && $m[2] !== '00') ? ['hours' => (int) $m[1], 'minutes' => (int) $m[2]] : null;
        $periods = [];
        foreach ($rows as $row) {
            $day = strtoupper((string) ($row['day'] ?? ''));
            $open = $time((string) ($row['open'] ?? ''));
            $close = $time((string) ($row['close'] ?? ''));
            if (! in_array($day, $days, true) || $open === null || $close === null || $open['hours'] === 24) {
                continue;
            }
            $closeDay = [$close['hours'], $close['minutes']] <= [$open['hours'], $open['minutes']] ? $days[(array_search($day, $days, true) + 1) % 7] : $day;
            $periods[] = ['openDay' => $day, 'openTime' => $open, 'closeDay' => $closeDay, 'closeTime' => $close];
        }

        return $periods;
    }

    /**
     * Periods as comparable text ("MONDAY 09:00-MONDAY 18:00|…"); Google leaves zero minutes out.
     *
     * @param  array<int, mixed>  $periods
     */
    public static function hoursKey(array $periods): string
    {
        $time = static fn (mixed $t): string => sprintf('%02d:%02d', (int) data_get($t, 'hours', 0), (int) data_get($t, 'minutes', 0));
        $keys = array_map(fn (mixed $p): string => data_get($p, 'openDay').' '.$time(data_get($p, 'openTime')).'-'.data_get($p, 'closeDay').' '.$time(data_get($p, 'closeTime')), $periods);
        sort($keys);

        return implode('|', $keys);
    }

    /** @return array<string, list<mixed>> attribute name => values, as the profile has them now */
    public function attributes(CoreIntegration $integration, string $location): array
    {
        $response = $this->google->get($integration, self::V1.$location.'/attributes', [], 'google_business_profile');
        if (! $response->successful()) {
            throw new RuntimeException('İşletme Profili özellikleri okunamadı: '.self::reason($response));
        }
        $out = [];
        foreach ((array) $response->json('attributes') as $attribute) {
            if (is_array($attribute) && filled($attribute['name'] ?? null)) {
                $out[(string) $attribute['name']] = array_values((array) ($attribute['values'] ?? []));
            }
        }

        return $out;
    }

    /**
     * @param  list<array{name: string, values: list<mixed>}>  $values  attributes to set (a masked name without a value is cleared)
     * @param  list<string>  $mask
     */
    private function writeAttributes(CoreIntegration $integration, string $location, array $values, array $mask): void
    {
        $this->call($integration, 'patch', self::V1.$location.'/attributes?attributeMask='.implode(',', $mask), [
            'name' => $location.'/attributes',
            'attributes' => array_map(fn (array $a): array => ['name' => $a['name'], 'valueType' => 'BOOL', 'values' => $a['values']], $values),
        ]);
    }

    /**
     * One special-hours row as Google's period: `{date: Y-m-d, closed: true}` or `{date, open: "HH:MM", close: "HH:MM"}`.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function specialPeriod(array $row): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) ($row['date'] ?? ''), $d) !== 1) {
            return null;
        }
        $date = ['year' => (int) $d[1], 'month' => (int) $d[2], 'day' => (int) $d[3]];
        if ((bool) ($row['closed'] ?? false)) {
            return ['startDate' => $date, 'endDate' => $date, 'closed' => true];
        }
        $time = static fn (string $t): ?array => preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t, $m) === 1 ? ['hours' => (int) $m[1], 'minutes' => (int) $m[2]] : null;
        $open = $time((string) ($row['open'] ?? ''));
        $close = $time((string) ($row['close'] ?? ''));
        if ($open === null || $close === null || [$close['hours'], $close['minutes']] <= [$open['hours'], $open['minutes']]) {
            return null;
        }

        return ['startDate' => $date, 'endDate' => $date, 'openTime' => $open, 'closeTime' => $close];
    }

    /** @param  array<string, mixed>  $period */
    public static function periodDate(array $period): string
    {
        $date = (array) ($period['startDate'] ?? []);

        return sprintf('%04d-%02d-%02d', (int) ($date['year'] ?? 0), (int) ($date['month'] ?? 0), (int) ($date['day'] ?? 0));
    }

    /** @return array<string, mixed> the location's live description, hours, website link, phone and categories */
    private function fields(CoreIntegration $integration, string $location): array
    {
        $response = $this->google->get($integration, self::V1.$location, ['readMask' => 'profile,specialHours,websiteUri,regularHours,phoneNumbers,categories'], 'google_business_profile');
        if (! $response->successful()) {
            throw new RuntimeException('İşletme Profili okunamadı: '.mb_substr((string) (data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status()), 0, 300));
        }

        return (array) $response->json();
    }

    /**
     * A plan row as a Google service item: the predefined type, or a free-form service under its category.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>|null
     */
    public static function serviceItem(array $service): ?array
    {
        $description = trim((string) ($service['description'] ?? ''));
        $typeId = trim((string) ($service['service_type_id'] ?? ''));
        if ($typeId !== '') {
            return ['structuredServiceItem' => array_filter(['serviceTypeId' => $typeId, 'description' => $description])];
        }
        $name = trim((string) ($service['name'] ?? ''));
        $category = (string) ($service['category_id'] ?? '');
        if ($name === '' || ! str_starts_with($category, 'categories/')) {
            return null;
        }

        return ['freeFormServiceItem' => ['category' => $category, 'label' => array_filter(['displayName' => $name, 'description' => $description, 'languageCode' => 'tr'])]];
    }

    /**
     * Same service: the same predefined type, or a free-form one with the same name (case / Turkish letters folded).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $item
     */
    private static function containsItem(array $items, array $item): bool
    {
        $type = (string) data_get($item, 'structuredServiceItem.serviceTypeId', '');
        $label = SeoText::fold((string) data_get($item, 'freeFormServiceItem.label.displayName', ''));
        foreach ($items as $other) {
            if ($type !== '' && (string) data_get($other, 'structuredServiceItem.serviceTypeId', '') === $type) {
                return true;
            }
            if ($label !== '' && SeoText::fold((string) data_get($other, 'freeFormServiceItem.label.displayName', '')) === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $location
     * @return list<string>
     */
    private static function categoryNames(array $location): array
    {
        return array_values(array_filter(array_map(fn (mixed $c): string => (string) data_get($c, 'name', ''), (array) data_get($location, 'categories.additionalCategories', []))));
    }

    /** @return array<string, mixed> the location's live categories and service items */
    private function profile(CoreIntegration $integration, string $location): array
    {
        $response = $this->google->get($integration, self::V1.$location, ['readMask' => 'categories,serviceItems'], 'google_business_profile');
        if (! $response->successful()) {
            throw new RuntimeException('İşletme Profili okunamadı: '.mb_substr((string) (data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status()), 0, 300));
        }

        return (array) $response->json();
    }

    /** @return array{0: CoreIntegration, 1: string} integration and "accounts/{a}/locations/{l}" */
    public function location(int $assetId): array
    {
        $resourceId = CoreAssetBinding::query()->where('digital_asset_id', $assetId)->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->value('external_resource_id');
        $resource = $resourceId !== null ? CoreExternalResource::query()->with('integration')->find($resourceId) : null;
        if ($resource === null || ! $resource->integration instanceof CoreIntegration) {
            throw new RuntimeException('İşletme Profili konumu bağlı değil.');
        }
        $external = (string) $resource->external_id;
        if (preg_match('#^(accounts/[^/]+)/locations/([^/]+)$#', $external, $m) === 1) {
            return [$resource->integration, $m[1].'/locations/'.$m[2]];
        }
        $account = (string) ($resource->parent_external_id ?? '');
        $location = str_starts_with($external, 'locations/') ? substr($external, strlen('locations/')) : '';
        if ($location === '') {
            throw new RuntimeException('İşletme Profili konumunun kimliği eksik; profili yeniden bağlayın.');
        }
        if (! str_starts_with($account, 'accounts/')) {
            // The daily collection remembers the account when it can; a write does not wait for it (2026-10-07: approved
            // posts of four profiles failed because the account was never stored).
            $account = $this->findAccount($resource->integration, 'locations/'.$location);
            $resource->forceFill(['parent_external_id' => $account])->save();
        }

        return [$resource->integration, $account.'/locations/'.$location];
    }

    /** The Google account that holds the location (the v4 post / reply / photo address needs it). */
    private function findAccount(CoreIntegration $integration, string $locationName): string
    {
        $accounts = $this->google->get($integration, 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts', ['pageSize' => 20], GoogleScopeRegistry::CAPABILITY_GBP);
        if (! $accounts->successful()) {
            throw new RuntimeException('İşletme Profili: konumun Google hesabı okunamadı ('.self::reason($accounts).').');
        }
        foreach ((array) $accounts->json('accounts') as $account) {
            $name = is_array($account) ? trim((string) ($account['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $token = null;
            $pages = 0;
            do {
                $response = $this->google->get($integration, self::V1.$name.'/locations', array_filter(['readMask' => 'name', 'pageSize' => 100, 'pageToken' => $token]), GoogleScopeRegistry::CAPABILITY_GBP);
                if (! $response->successful()) {
                    break;
                }
                foreach ((array) $response->json('locations') as $candidate) {
                    if (is_array($candidate) && ($candidate['name'] ?? null) === $locationName) {
                        return $name;
                    }
                }
                $token = is_string($response->json('nextPageToken')) && $response->json('nextPageToken') !== '' ? (string) $response->json('nextPageToken') : null;
            } while ($token !== null && ++$pages < 50);
        }

        throw new RuntimeException('İşletme Profili: bu konum, bağlantıyı yapan Google kullanıcısının hesaplarında bulunamadı. Profilde bu kullanıcıya yönetici ya da sahip yetkisi verilmeli.');
    }

    private static function reason(Response $response): string
    {
        return trim(mb_substr((string) ($response->json('error.message') ?? ''), 0, 200)) ?: 'HTTP '.$response->status();
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function call(CoreIntegration $integration, string $method, string $url, array $body = []): array
    {
        $response = $this->google->writeBusinessProfile($integration, $method, $url, $body);
        if (! $response->successful()) {
            throw new RuntimeException('İşletme Profili: '.mb_substr((string) (data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status()), 0, 300));
        }

        return (array) $response->json();
    }
}
