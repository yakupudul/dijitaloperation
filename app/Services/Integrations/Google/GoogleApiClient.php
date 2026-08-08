<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleApiClient
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
    ) {}

    public function get(CoreIntegration $integration, string $url, array $query = []): Response
    {
        return $this->request($integration, 'get', $url, $query);
    }

    public function getAds(CoreIntegration $integration, string $path): Response
    {
        $developerToken = GoogleOAuthConfig::developerToken();
        if ($developerToken === null) {
            throw new RuntimeException('GOOGLE_ADS_DEVELOPER_TOKEN is missing.');
        }

        $url = GoogleOAuthConfig::adsApiUrl($path);

        $token = $this->oauth->validAccessToken($integration);
        if ($token === null) {
            throw new RuntimeException('Google authorization is missing or expired.');
        }

        return Http::withToken($token)
            ->withHeaders([
                'developer-token' => $developerToken,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->get($url);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(CoreIntegration $integration, string $method, string $url, array $query = []): Response
    {
        $token = $this->oauth->validAccessToken($integration);
        if ($token === null) {
            throw new RuntimeException('Google authorization is missing or expired.');
        }

        /** @var PendingRequest $pending */
        $pending = Http::withToken($token)->timeout(30);

        try {
            /** @var Response $response */
            $response = $pending->{$method}($url, $query);
        } catch (\Throwable $e) {
            Log::warning('Google API network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            throw new RuntimeException('Google API network failure.');
        }

        if ($response->status() === 401) {
            $refreshed = $this->oauth->refreshAccessToken($integration);
            if ($refreshed !== null) {
                $response = Http::withToken($refreshed)->timeout(30)->{$method}($url, $query);
            }
        }

        return $response;
    }
}
