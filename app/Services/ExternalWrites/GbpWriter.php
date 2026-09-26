<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Services\Integrations\Google\GoogleApiClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-073: Business Profile writes. A review reply (undo restores the previous reply or deletes it) and a local
 * post (undo deletes it). Nothing else on the profile (hours, categories, services, photos) is changed.
 */
final class GbpWriter
{
    private const string BASE = 'https://mybusiness.googleapis.com/v4/';

    public function __construct(private readonly GoogleApiClient $google) {}

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $payload = (array) $action->request_payload;

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
        if (isset($payload['calendar_id'])) {
            DB::table('content_calendar_items')->where('id', (int) $payload['calendar_id'])->update(['status' => 'published', 'published_at' => now(), 'external_ref' => $name, 'updated_at' => now()]);
        }

        return ['status' => 'succeeded', 'post' => $name, 'search_url' => $created['searchUrl'] ?? null];
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        [$integration, $parent] = $this->location((int) $action->digital_asset_id);
        $result = (array) $action->result;
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
