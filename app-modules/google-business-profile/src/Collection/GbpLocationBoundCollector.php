<?php

namespace MoxDop\GoogleBusinessProfile\Collection;

use App\Contracts\Integrations\CollectsBoundProviderData;
use App\Models\CoreAssetBinding;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Integrations\BoundCollectionGuard;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Binding-based GBP location profile collector (read-only).
 * Produces backwards-compatible gbp_location_access Evidence when API access works.
 * Otherwise records a clean setup_required / access failure — does not block other collectors.
 */
final class GbpLocationBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'google-business-profile';

    public const string CAPABILITY = 'google_business_profile';

    public const string EVIDENCE_TYPE = 'gbp_location_access';

    private const string LOCATION_BASE_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const string READ_MASK = 'name,title,storefrontAddress,websiteUri,phoneNumbers,categories';

    public function __construct(
        private readonly BoundCollectionGuard $guard,
        private readonly GoogleApiClient $client,
    ) {}

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function moduleId(): string
    {
        return self::MODULE_ID;
    }

    public function collect(CoreAssetBinding $binding): Run
    {
        $ctx = $this->guard->assertCollectable($binding, self::CAPABILITY);
        $asset = $ctx['asset'];
        $resource = $ctx['resource'];
        $integration = $ctx['integration'];

        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            throw new RuntimeException('GBP collection requires a Google Integration.');
        }

        $locationName = $this->normalizeLocationName((string) $resource->external_id);
        $observedAt = now();

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'core_asset_binding_id' => $binding->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => $observedAt,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'collect_live_data',
                'capability' => self::CAPABILITY,
                'provider' => ProviderRegistry::GOOGLE,
                'external_resource_id' => $resource->id,
                'external_id' => $locationName,
                'resource_display_name' => $resource->display_name,
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ],
        ]);

        try {
            $response = $this->client->get($integration, self::LOCATION_BASE_URL.$locationName, [
                'readMask' => self::READ_MASK,
            ]);

            $payload = $this->normalizeLocationEvidence($locationName, $response->status(), $response->json());

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE,
                'title' => 'Google Business Profile location access',
                'payload' => $payload,
                'observed_at' => $observedAt,
            ]);

            $ok = ($payload['ok'] ?? false) === true;
            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => $ok,
                    'setup_required' => ! $ok && in_array($response->status(), [403, 404], true),
                    'safe_error' => $ok ? null : (string) ($payload['status_or_error'] ?? 'GBP access failed'),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GBP bound collector failed', [
                'binding_id' => $binding->id,
                'exception' => $e::class,
            ]);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => false,
                    'setup_required' => true,
                    'safe_error' => $e->getMessage(),
                ]),
            ]);
        }

        return $run->fresh(['evidence']) ?? $run;
    }

    private function normalizeLocationName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new RuntimeException('GBP External Resource has an empty location id.');
        }
        if (str_starts_with($trimmed, 'locations/') || preg_match('/^accounts\/[^\/]+\/locations\/[^\/]+$/', $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match('/^[0-9]+$/', $trimmed) === 1) {
            return 'locations/'.$trimmed;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLocationEvidence(string $requestedLocationName, int $statusCode, mixed $body): array
    {
        $body = is_array($body) ? $body : null;
        $ok = $statusCode === 200 && is_array($body);

        $storefront = null;
        if (is_array($body) && isset($body['storefrontAddress']) && is_array($body['storefrontAddress'])) {
            $address = $body['storefrontAddress'];
            $lines = $address['addressLines'] ?? [];
            $storefront = [
                'region_code' => is_string($address['regionCode'] ?? null) ? $address['regionCode'] : null,
                'postal_code' => is_string($address['postalCode'] ?? null) ? $address['postalCode'] : null,
                'administrative_area' => is_string($address['administrativeArea'] ?? null) ? $address['administrativeArea'] : null,
                'locality' => is_string($address['locality'] ?? null) ? $address['locality'] : null,
                'address_lines' => is_array($lines)
                    ? array_values(array_filter($lines, fn ($line): bool => is_string($line) && $line !== ''))
                    : [],
            ];
        }

        $primaryPhone = null;
        if (is_array($body) && isset($body['phoneNumbers']) && is_array($body['phoneNumbers'])) {
            $primaryPhone = is_string($body['phoneNumbers']['primaryPhone'] ?? null)
                ? $body['phoneNumbers']['primaryPhone']
                : null;
        }

        $primaryCategory = null;
        if (is_array($body) && isset($body['categories']['primaryCategory']) && is_array($body['categories']['primaryCategory'])) {
            $cat = $body['categories']['primaryCategory'];
            $primaryCategory = is_string($cat['displayName'] ?? null)
                ? $cat['displayName']
                : (is_string($cat['name'] ?? null) ? $cat['name'] : null);
        }

        $statusOrError = match (true) {
            $ok => (string) $statusCode,
            $statusCode === 403 => 'setup_required: Google Business Profile API access denied or scope missing.',
            $statusCode === 404 => 'setup_required: GBP location not found for this authorization.',
            default => 'gbp_http_'.$statusCode,
        };

        return [
            'requested_location_name' => $requestedLocationName,
            'location_name' => is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : null,
            'title' => is_array($body) && is_string($body['title'] ?? null) ? $body['title'] : null,
            'website_uri' => is_array($body) && is_string($body['websiteUri'] ?? null) ? $body['websiteUri'] : null,
            'primary_phone' => $primaryPhone,
            'primary_category' => $primaryCategory,
            'storefront_address' => $storefront,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $ok ? null : 'gbp_api_error',
            'source' => 'bound_collector',
        ];
    }
}
