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
 * Read-only Google Business Profile location access probe (no GBP writes).
 */
class GoogleBusinessProfileConnectionProbeService
{
    public const MODULE_ID = 'google-business-profile-connector';

    public const CONNECTION_TYPE = 'google_business_profile_api';

    public const ASSET_TYPE = 'google_business_profile';

    public const EVIDENCE_TYPE_GBP_LOCATION_ACCESS = 'gbp_location_access';

    private const LOCATION_BASE_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const READ_MASK = 'name,title,storeCode,languageCode,storefrontAddress,websiteUri,phoneNumbers,categories';

    /**
     * Verify a GBP connection can read the configured location and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('GBP probe requires a CoreConnection with type google_business_profile_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('GBP probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('GBP probe requires a google_business_profile Digital Asset.');
        }

        $locationName = $this->resolveLocationName($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('GBP probe requires an encrypted access_token credential.');
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
                'probe' => 'location-get',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getLocation($locationName, $accessToken);
            $payload = $this->normalizeLocationEvidence($locationName, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_GBP_LOCATION_ACCESS,
                'title' => 'Google Business Profile location access',
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
                    : 'gbp_probe_failed';

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

    private function resolveLocationName(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];

        $configured = isset($config['location_name']) && is_string($config['location_name'])
            ? trim($config['location_name'])
            : '';

        if ($configured !== '') {
            return $this->normalizeLocationName($configured);
        }

        $locationId = isset($config['location_id']) && is_string($config['location_id'])
            ? trim($config['location_id'])
            : '';

        if ($locationId !== '') {
            return $this->normalizeLocationName($locationId);
        }

        throw new InvalidArgumentException('GBP probe requires config.location_name or config.location_id.');
    }

    private function normalizeLocationName(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('GBP probe requires a non-empty location identifier.');
        }

        if (str_starts_with($trimmed, 'locations/')) {
            return $trimmed;
        }

        if (preg_match('/^accounts\/[^\/]+\/locations\/[^\/]+$/', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^[0-9]+$/', $trimmed) === 1) {
            return 'locations/'.$trimmed;
        }

        throw new InvalidArgumentException('GBP probe location must be locations/{id}, accounts/{account}/locations/{id}, or a numeric location id.');
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
    private function getLocation(string $locationName, string $accessToken): array
    {
        $url = self::LOCATION_BASE_URL.$locationName;

        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-GoogleBusinessProfileConnector/1.0',
                ])
                ->get($url, [
                    'readMask' => self::READ_MASK,
                ]);

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
     *     requested_location_name: string,
     *     location_name: string|null,
     *     title: string|null,
     *     website_uri: string|null,
     *     primary_phone: string|null,
     *     primary_category: string|null,
     *     storefront_address: array{
     *         region_code: string|null,
     *         postal_code: string|null,
     *         administrative_area: string|null,
     *         locality: string|null,
     *         address_lines: list<string>
     *     }|null,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeLocationEvidence(string $requestedLocationName, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $locationName = is_array($body) && isset($body['name']) && is_string($body['name'])
            ? $body['name']
            : null;
        $title = is_array($body) && isset($body['title']) && is_string($body['title'])
            ? $body['title']
            : null;
        $websiteUri = is_array($body) && isset($body['websiteUri']) && is_string($body['websiteUri'])
            ? $body['websiteUri']
            : null;

        $primaryPhone = null;
        if (is_array($body) && isset($body['phoneNumbers']) && is_array($body['phoneNumbers'])) {
            $primary = $body['phoneNumbers']['primaryPhone'] ?? null;
            $primaryPhone = is_string($primary) ? $primary : null;
        }

        $primaryCategory = null;
        if (is_array($body) && isset($body['categories']) && is_array($body['categories'])) {
            $primary = $body['categories']['primaryCategory'] ?? null;
            if (is_array($primary) && isset($primary['displayName']) && is_string($primary['displayName'])) {
                $primaryCategory = $primary['displayName'];
            } elseif (is_array($primary) && isset($primary['name']) && is_string($primary['name'])) {
                $primaryCategory = $primary['name'];
            }
        }

        $storefrontAddress = $this->normalizeStorefrontAddress(
            is_array($body) ? ($body['storefrontAddress'] ?? null) : null,
        );

        $ok = $errorClass === null && $statusCode === 200 && $locationName !== null;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode === 404) {
            $statusOrError = 'location_not_found';
        }

        if ($errorClass === null && $statusCode === 200 && $locationName === null) {
            $statusOrError = 'location_data_missing';
        }

        return [
            'requested_location_name' => $requestedLocationName,
            'location_name' => $locationName,
            'title' => $title,
            'website_uri' => $websiteUri,
            'primary_phone' => $primaryPhone,
            'primary_category' => $primaryCategory,
            'storefront_address' => $storefrontAddress,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }

    /**
     * @return array{
     *     region_code: string|null,
     *     postal_code: string|null,
     *     administrative_area: string|null,
     *     locality: string|null,
     *     address_lines: list<string>
     * }|null
     */
    private function normalizeStorefrontAddress(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $lines = [];
        if (isset($address['addressLines']) && is_array($address['addressLines'])) {
            foreach ($address['addressLines'] as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }

        $regionCode = isset($address['regionCode']) && is_string($address['regionCode'])
            ? trim($address['regionCode'])
            : null;
        $postalCode = isset($address['postalCode']) && is_string($address['postalCode'])
            ? trim($address['postalCode'])
            : null;
        $adminArea = isset($address['administrativeArea']) && is_string($address['administrativeArea'])
            ? trim($address['administrativeArea'])
            : null;
        $locality = isset($address['locality']) && is_string($address['locality'])
            ? trim($address['locality'])
            : null;

        if ($lines === [] && $regionCode === null && $postalCode === null && $adminArea === null && $locality === null) {
            return null;
        }

        return [
            'region_code' => $regionCode === '' ? null : $regionCode,
            'postal_code' => $postalCode === '' ? null : $postalCode,
            'administrative_area' => $adminArea === '' ? null : $adminArea,
            'locality' => $locality === '' ? null : $locality,
            'address_lines' => $lines,
        ];
    }
}
