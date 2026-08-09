<?php

namespace App\Services;

use App\Models\CoreConnection;
use App\Models\Evidence;
use App\Models\Run;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only DataForSEO account probe (no SERP tasks / external writes).
 *
 * Compatibility technical debt: site-scoped CoreConnection path.
 * Normal new UX uses Settings → Integrations → DataForSEO (agency Integration).
 * Do not encourage creation of new dataforseo CoreConnections.
 */
class DataForSeoConnectionProbeService
{
    public const MODULE_ID = 'dataforseo-connector';

    public const CONNECTION_TYPE = 'dataforseo';

    public const EVIDENCE_TYPE_DATAFORSEO_ACCOUNT = 'dataforseo_account';

    private const USER_DATA_URL = 'https://api.dataforseo.com/v3/appendix/user_data';

    /**
     * Verify a DataForSEO connection can read account user_data and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('DataForSEO probe requires a CoreConnection with type dataforseo.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('DataForSEO probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('DataForSEO probe requires a website Digital Asset.');
        }

        $credentials = $this->apiCredentials($connection);

        if ($credentials === null) {
            throw new InvalidArgumentException('DataForSEO probe requires encrypted login and password credentials.');
        }

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'connector' => self::CONNECTION_TYPE,
                'probe' => 'appendix-user-data',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getUserData($credentials['login'], $credentials['password']);
            $payload = $this->normalizeAccountEvidence($fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_DATAFORSEO_ACCOUNT,
                'title' => 'DataForSEO account access',
                'payload' => $payload,
                'observed_at' => $observedAt,
            ]);

            if (($payload['ok'] ?? false) === true) {
                $connection->forceFill([
                    'last_success_at' => $observedAt,
                    'last_error' => null,
                ])->save();

                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                ]);
            } else {
                $error = is_string($payload['status_or_error'] ?? null)
                    ? $payload['status_or_error']
                    : 'dataforseo_probe_failed';

                $connection->forceFill([
                    'last_error' => $error,
                ])->save();

                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                    'metadata' => array_merge($run->metadata ?? [], [
                        'probe_ok' => false,
                        'status_or_error' => $error,
                    ]),
                ]);
            }
        } catch (Throwable $exception) {
            $connection->forceFill([
                'last_error' => 'probe_exception: '.$exception->getMessage(),
            ])->save();

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }

        return $run->fresh(['evidence', 'coreConnection', 'digitalAsset']) ?? $run;
    }

    /**
     * @return array{login: string, password: string}|null
     */
    private function apiCredentials(CoreConnection $connection): ?array
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $login = isset($payload['login']) && is_string($payload['login'])
            ? trim($payload['login'])
            : '';
        $password = isset($payload['password']) && is_string($payload['password'])
            ? trim($payload['password'])
            : '';

        if ($login === '' || $password === '') {
            return null;
        }

        return [
            'login' => $login,
            'password' => $password,
        ];
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getUserData(string $login, string $password): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout(30)
                ->acceptJson()
                ->withBasicAuth($login, $password)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-DataForSeoConnector/1.0',
                ])
                ->get(self::USER_DATA_URL);

            $json = $response->json();

            return [
                'status_code' => $response->status(),
                'error_class' => null,
                'body' => is_array($json) ? $json : null,
            ];
        } catch (ConnectionException $exception) {
            return [
                'status_code' => null,
                'error_class' => 'connection',
                'body' => null,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }  $fetch
     * @return array{
     *     account_login: string|null,
     *     timezone: string|null,
     *     balance: float|null,
     *     api_status_code: int|null,
     *     api_status_message: string|null,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeAccountEvidence(array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $apiStatusCode = null;
        $apiStatusMessage = null;
        $accountLogin = null;
        $timezone = null;
        $balance = null;

        if (is_array($body)) {
            if (isset($body['status_code']) && is_numeric($body['status_code'])) {
                $apiStatusCode = (int) $body['status_code'];
            }

            if (isset($body['status_message']) && is_string($body['status_message'])) {
                $apiStatusMessage = $body['status_message'];
            }

            $tasks = isset($body['tasks']) && is_array($body['tasks']) ? $body['tasks'] : [];
            $firstTask = isset($tasks[0]) && is_array($tasks[0]) ? $tasks[0] : null;

            if (is_array($firstTask)) {
                if (isset($firstTask['status_code']) && is_numeric($firstTask['status_code'])) {
                    $apiStatusCode = (int) $firstTask['status_code'];
                }

                if (isset($firstTask['status_message']) && is_string($firstTask['status_message'])) {
                    $apiStatusMessage = $firstTask['status_message'];
                }

                $results = isset($firstTask['result']) && is_array($firstTask['result'])
                    ? $firstTask['result']
                    : [];
                $firstResult = isset($results[0]) && is_array($results[0]) ? $results[0] : null;

                if (is_array($firstResult)) {
                    if (isset($firstResult['login']) && is_string($firstResult['login'])) {
                        $accountLogin = $firstResult['login'];
                    }

                    if (isset($firstResult['timezone']) && is_string($firstResult['timezone'])) {
                        $timezone = $firstResult['timezone'];
                    }

                    $money = isset($firstResult['money']) && is_array($firstResult['money'])
                        ? $firstResult['money']
                        : null;

                    if (is_array($money) && isset($money['balance']) && is_numeric($money['balance'])) {
                        $balance = (float) $money['balance'];
                    }
                }
            }
        }

        $authFailed = $statusCode === 401
            || $statusCode === 403
            || ($apiStatusCode !== null && $apiStatusCode >= 40100 && $apiStatusCode < 40200);

        $ok = $errorClass === null
            && $statusCode === 200
            && $apiStatusCode === 20000
            && is_string($accountLogin)
            && $accountLogin !== '';

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($authFailed) {
            $statusOrError = 'auth_failed';
        } elseif ($errorClass === null && $statusCode === 200 && ! $ok) {
            $statusOrError = 'account_data_missing';
        }

        return [
            'account_login' => $accountLogin,
            'timezone' => $timezone,
            'balance' => $balance,
            'api_status_code' => $apiStatusCode,
            'api_status_message' => $apiStatusMessage,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }
}
