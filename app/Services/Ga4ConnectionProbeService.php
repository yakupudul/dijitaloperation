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
 * Read-only Google Analytics 4 property probe (no GA4 writes).
 */
class Ga4ConnectionProbeService
{
    public const MODULE_ID = 'ga4-connector';

    public const CONNECTION_TYPE = 'ga4';

    public const EVIDENCE_TYPE_GA4_PROPERTY = 'ga4_property';

    private const ACCOUNT_SUMMARIES_URL = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';

    /**
     * Verify a GA4 connection can read the configured property and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('GA4 probe requires a CoreConnection with type ga4.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('GA4 probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('GA4 probe requires a website Digital Asset.');
        }

        $propertyId = $this->resolvePropertyId($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('GA4 probe requires an encrypted access_token credential.');
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
                'probe' => 'account-summaries',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getAccountSummaries($accessToken);
            $payload = $this->normalizePropertyEvidence($propertyId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_GA4_PROPERTY,
                'title' => 'GA4 property access',
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
                    : 'ga4_probe_failed';

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

    private function resolvePropertyId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $configured = isset($config['property_id']) && is_string($config['property_id'])
            ? trim($config['property_id'])
            : '';

        if ($configured === '') {
            throw new InvalidArgumentException('GA4 probe requires config.property_id.');
        }

        return $this->normalizePropertyResourceName($configured);
    }

    private function accessToken(CoreConnection $connection): ?string
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $token = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';

        return $token !== '' ? $token : null;
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getAccountSummaries(string $accessToken): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-Ga4Connector/1.0',
                ])
                ->get(self::ACCOUNT_SUMMARIES_URL);

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
     *     requested_property_id: string,
     *     matched_property_id: string|null,
     *     display_name: string|null,
     *     account_display_name: string|null,
     *     property_count: int,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizePropertyEvidence(string $requestedPropertyId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];
        $summaries = [];

        if (is_array($body) && isset($body['accountSummaries']) && is_array($body['accountSummaries'])) {
            $summaries = $body['accountSummaries'];
        }

        $matchedPropertyId = null;
        $displayName = null;
        $accountDisplayName = null;
        $propertyCount = 0;

        foreach ($summaries as $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $propertySummaries = isset($summary['propertySummaries']) && is_array($summary['propertySummaries'])
                ? $summary['propertySummaries']
                : [];

            foreach ($propertySummaries as $propertySummary) {
                if (! is_array($propertySummary)) {
                    continue;
                }

                $candidate = isset($propertySummary['property']) && is_string($propertySummary['property'])
                    ? $this->normalizePropertyResourceName($propertySummary['property'])
                    : null;

                if ($candidate === null) {
                    continue;
                }

                $propertyCount++;

                if ($candidate === $requestedPropertyId) {
                    $matchedPropertyId = $candidate;
                    $displayName = isset($propertySummary['displayName']) && is_string($propertySummary['displayName'])
                        ? $propertySummary['displayName']
                        : null;
                    $accountDisplayName = isset($summary['displayName']) && is_string($summary['displayName'])
                        ? $summary['displayName']
                        : null;
                }
            }
        }

        $ok = $errorClass === null && $statusCode === 200 && $matchedPropertyId !== null;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode === 200 && $matchedPropertyId === null) {
            $statusOrError = 'property_not_found';
        }

        return [
            'requested_property_id' => $requestedPropertyId,
            'matched_property_id' => $matchedPropertyId,
            'display_name' => $displayName,
            'account_display_name' => $accountDisplayName,
            'property_count' => $propertyCount,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }

    private function normalizePropertyResourceName(string $propertyId): string
    {
        $trimmed = trim($propertyId);

        if (str_starts_with($trimmed, 'properties/')) {
            return $trimmed;
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return 'properties/'.$trimmed;
        }

        return $trimmed;
    }
}
