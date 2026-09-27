<?php

namespace App\Services\Collection\Activity;

use App\Models\CoreExternalResource;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsClientFactory;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Support\Time\SafeTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the provider — read-only, one tiny request — whether account structure (campaigns, ad groups / ad sets,
 * ads, creatives) changed since a moment.
 *
 * - Google Ads: GAQL on change_status (last_change_date_time window, LIMIT 1) through the governed search client.
 * - Meta: campaigns / adsets / ads edges filtered on updated_time > since, limit 1, stopping at the first hit.
 *
 * Returns null when the answer is unknown (provider error, missing identity); callers then collect.
 */
class StructureChangeDetector
{
    /** Google Ads change_status only answers for the last 90 days. */
    private const int GOOGLE_ADS_CHANGE_STATUS_MAX_DAYS = 89;

    public function __construct(
        private readonly GoogleAdsClientFactory $googleAds,
        private readonly MetaApiClient $meta,
    ) {}

    public function changedSince(CoreExternalResource $resource, string $provider, CarbonImmutable $since): ?bool
    {
        try {
            return match ($provider) {
                'GOOGLE_ADS' => $this->googleAds($resource, $since),
                'META_ADS' => $this->meta($resource, $since),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::warning('collection.activity.change_check_failed', [
                'external_resource_id' => $resource->id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function googleAds(CoreExternalResource $resource, CarbonImmutable $since): ?bool
    {
        $integration = $resource->integration;
        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        if ($integration === null || $customerId === '') {
            return null;
        }
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;
        $timezone = SafeTimezone::normalize((string) ($metadata['time_zone'] ?? $metadata['timezone'] ?? 'UTC')) ?: 'UTC';

        $now = CarbonImmutable::now($timezone);
        $from = $since->setTimezone($timezone);
        $oldest = $now->subDays(self::GOOGLE_ADS_CHANGE_STATUS_MAX_DAYS);
        if ($from->lessThan($oldest)) {
            $from = $oldest;
        }
        $query = sprintf(
            "SELECT change_status.resource_name, change_status.last_change_date_time FROM change_status WHERE change_status.last_change_date_time >= '%s' AND change_status.last_change_date_time <= '%s' ORDER BY change_status.last_change_date_time DESC LIMIT 1",
            $from->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        );

        $response = $this->googleAds->search($integration, $customerId, $query, $login);
        if (! $response->successful()) {
            return null;
        }
        $results = $response->json('results');

        return is_array($results) && $results !== [];
    }

    private function meta(CoreExternalResource $resource, CarbonImmutable $since): ?bool
    {
        $integration = $resource->integration;
        $account = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        if ($integration === null || $account === '') {
            return null;
        }
        foreach (['campaigns' => 'campaign', 'adsets' => 'adset', 'ads' => 'ad'] as $edge => $prefix) {
            $payload = $this->meta->get($integration, 'act_'.$account.'/'.$edge, [
                'fields' => 'id,updated_time',
                'limit' => 1,
                'filtering' => json_encode([[
                    'field' => $prefix.'.updated_time',
                    'operator' => 'GREATER_THAN',
                    'value' => $since->getTimestamp(),
                ]], JSON_THROW_ON_ERROR),
            ]);
            $data = $payload['data'] ?? null;
            if (is_array($data) && $data !== []) {
                return true;
            }
        }

        return false;
    }
}
