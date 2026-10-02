<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use Illuminate\Http\Client\Response;

/**
 * Canonical Google Ads HTTP client facade for production collection.
 * Tokens resolve only through GoogleApiClient → GoogleCredentialBroker.
 * Every provider read passes through GoogleAdsRequestGovernor so independent
 * dataset families cannot overwhelm the same customer/developer-token quota.
 */
final class GoogleAdsClientFactory
{
    public function __construct(
        private readonly GoogleApiClient $http,
        private readonly GoogleAdsRequestGovernor $governor,
    ) {}

    public function search(
        CoreIntegration $integration,
        string $customerId,
        string $query,
        string $loginCustomerId,
        ?string $pageToken = null,
    ): Response {
        return $this->governor->run(
            $integration,
            $customerId,
            fn (): Response => $this->http->searchAds(
                $integration,
                $customerId,
                $query,
                $loginCustomerId,
                $pageToken,
            ),
        );
    }

    public function searchStream(
        CoreIntegration $integration,
        string $customerId,
        string $query,
        string $loginCustomerId,
    ): Response {
        $timeout = (int) config('moxdop-google-ads-collector.search_stream_timeout_seconds', 120);

        return $this->governor->run(
            $integration,
            $customerId,
            fn (): Response => $this->http->searchStreamAds(
                $integration,
                $customerId,
                $query,
                $loginCustomerId,
                'google_ads',
                $timeout,
            ),
        );
    }
}
