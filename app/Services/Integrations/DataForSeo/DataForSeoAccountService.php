<?php

namespace App\Services\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use RuntimeException;
use Throwable;

/**
 * Agency-level DataForSEO Test connection via free /v3/appendix/user_data.
 */
class DataForSeoAccountService
{
    public function __construct(
        private readonly DataForSeoCredentialResolver $resolver,
        private readonly DataForSeoApiClient $client,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(CoreIntegration $integration): array
    {
        $this->assertDataForSeo($integration);

        if (! $this->resolver->isConfigured($integration)) {
            $message = 'Configure DataForSEO API Login and API Password first.';
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }

        try {
            $response = $this->client->getUserData($integration);
            $account = $this->extractAccountSnapshot($response);

            if (($account['account_login'] ?? null) === null) {
                $message = 'DataForSEO returned an API error: account data missing.';
                $this->persistFailure($integration, $message, $response);

                return ['ok' => false, 'message' => $message];
            }

            $this->persistSuccess($integration, $account, $response);

            $balance = $account['balance'];
            $balanceText = is_float($balance) || is_int($balance)
                ? ' Balance (last fetched): '.$balance.' USD.'
                : '';

            return [
                'ok' => true,
                'message' => 'Connected as '.$account['account_login'].'.'.$balanceText,
            ];
        } catch (DataForSeoException $exception) {
            $message = $exception->getMessage();
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        } catch (Throwable) {
            $message = DataForSeoOperatorMessages::forTransport();
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }
    }

    /**
     * @return array{account_login: string|null, timezone: string|null, balance: float|null}
     */
    private function extractAccountSnapshot(DataForSeoResponse $response): array
    {
        $result = $response->firstResult() ?? [];

        $login = isset($result['login']) && is_string($result['login']) && $result['login'] !== ''
            ? $result['login']
            : null;
        $timezone = isset($result['timezone']) && is_string($result['timezone']) && $result['timezone'] !== ''
            ? $result['timezone']
            : null;

        $balance = null;
        $money = isset($result['money']) && is_array($result['money']) ? $result['money'] : null;
        if (is_array($money) && isset($money['balance']) && is_numeric($money['balance'])) {
            $balance = (float) $money['balance'];
        }

        return [
            'account_login' => $login,
            'timezone' => $timezone,
            'balance' => $balance,
        ];
    }

    /**
     * @param  array{account_login: string|null, timezone: string|null, balance: float|null}  $account
     */
    private function persistSuccess(CoreIntegration $integration, array $account, DataForSeoResponse $response): void
    {
        $config = is_array($integration->config) ? $integration->config : [];
        $now = now()->toIso8601String();

        $config['connection_status'] = 'connected';
        $config['account_login'] = $account['account_login'];
        $config['timezone'] = $account['timezone'];
        $config['balance'] = $account['balance'];
        $config['balance_checked_at'] = $now;
        $config['last_tested_at'] = $now;
        $config['last_provider_status_code'] = $response->statusCode;
        // Never persist full user_data / rates JSON.
        unset($config['user_data_raw'], $config['rates']);

        $integration->forceFill([
            'config' => $config,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function persistFailure(
        CoreIntegration $integration,
        string $message,
        ?DataForSeoResponse $response = null,
    ): void {
        $config = is_array($integration->config) ? $integration->config : [];
        $config['connection_status'] = 'issue';
        $config['last_tested_at'] = now()->toIso8601String();
        if ($response !== null) {
            $config['last_provider_status_code'] = $response->statusCode;
        }
        unset($config['user_data_raw'], $config['rates']);

        $integration->forceFill([
            'config' => $config,
            'last_error' => mb_substr($message, 0, 500),
        ])->save();
    }

    private function assertDataForSeo(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::DATAFORSEO) {
            throw new RuntimeException('Integration is not a DataForSEO provider.');
        }
    }
}
