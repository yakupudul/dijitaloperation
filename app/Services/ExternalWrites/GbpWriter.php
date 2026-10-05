<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\SeoTasks\SeoText;
use RuntimeException;
use Throwable;

/**
 * Business Profile writes. ADR-073: a review reply (undo restores the previous reply or deletes it) and a local post
 * (undo deletes it). ADR-077: categories and service items the Admin chose from a "Kategori ve hizmetler" plan are
 * added to what the profile has now (read just before the write; nothing existing is removed or changed, the primary
 * category stays); undo removes exactly what was added. Hours, description, photos and the rest are never changed.
 */
final class GbpWriter
{
    private const string BASE = 'https://mybusiness.googleapis.com/v4/';

    private const string V1 = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    public const int ADDITIONAL_MAX = 9;

    public function __construct(private readonly GoogleApiClient $google) {}

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $payload = (array) $action->request_payload;

        if ($action->action === ExternalWriteAction::ACTION_PROFILE_UPDATE) {
            return $this->addToProfile($integration, 'locations/'.substr($parent, (int) strrpos($parent, '/') + 1), $payload);
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
            $body['callToAction'] = ['actionType' => (string) ($payload['action_type'] ?? 'LEARN_MORE'), 'url' => (string) $payload['url']];
        }
        $created = $this->call($integration, 'post', self::BASE.$parent.'/localPosts', $body);
        $name = (string) ($created['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Gönderi oluşturuldu ama adı dönmedi.');
        }

        return ['status' => 'succeeded', 'post' => $name, 'search_url' => $created['searchUrl'] ?? null];
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $result = (array) $action->result;
        if ($action->action === ExternalWriteAction::ACTION_PROFILE_UPDATE) {
            return $this->removeFromProfile($integration, $result);
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
        if (! str_starts_with($account, 'accounts/') || $location === '') {
            throw new RuntimeException('Konumun hesap bilgisi yok; önce İşletme Profili verisini bir kez çekin.');
        }

        return [$resource->integration, $account.'/locations/'.$location];
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
