<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\Meta\MetaApiConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Compatibility boundary for Meta Graph behaviours that differ between
 * historical MOXDOP collectors and the currently supported Marketing API.
 *
 * The legacy entity snapshot collector used the Ad Account /adcreatives edge
 * with filtering=[{field:id,operator:IN,...}]. Meta now rejects that filter
 * with code 100. Translate only that exact legacy request into the supported
 * Graph multiple-ID node lookup and keep every other request on MetaApiClient.
 */
final class MetaApiClientCompatibility extends MetaApiClient
{
    public function __construct(
        private readonly MetaCredentialBroker $compatBroker,
        private readonly MetaCredentialResolver $compatCredentials,
    ) {
        parent::__construct($compatBroker);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(CoreIntegration $integration, string $path, array $query = []): array
    {
        $creativeIds = $this->legacyAdCreativeIds($path, $query);
        if ($creativeIds === null) {
            return parent::get($integration, $path, $query);
        }

        if ($creativeIds === []) {
            return ['data' => []];
        }

        $fields = isset($query['fields']) && is_string($query['fields']) && trim($query['fields']) !== ''
            ? trim($query['fields'])
            : 'id';

        $rows = [];
        foreach (array_chunk($creativeIds, 50) as $chunk) {
            foreach ($this->getNodesByIds($integration, $chunk, $fields) as $row) {
                $rows[] = $row;
            }
        }

        return ['data' => $rows];
    }

    /**
     * Detect only the unsupported legacy /adcreatives id-IN filter.
     * Returning null means the request must pass through unchanged.
     *
     * @param  array<string, scalar|null>  $query
     * @return list<string>|null
     */
    private function legacyAdCreativeIds(string $path, array $query): ?array
    {
        $normalizedPath = trim($path, '/');
        if (preg_match('#^act_[^/]+/adcreatives$#', $normalizedPath) !== 1) {
            return null;
        }

        $rawFiltering = $query['filtering'] ?? null;
        if (! is_string($rawFiltering) || trim($rawFiltering) === '') {
            return null;
        }

        $filters = json_decode($rawFiltering, true);
        if (! is_array($filters)) {
            return null;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            if (strtolower(trim((string) ($filter['field'] ?? ''))) !== 'id') {
                continue;
            }

            if (strtoupper(trim((string) ($filter['operator'] ?? ''))) !== 'IN') {
                continue;
            }

            $values = $filter['value'] ?? null;
            if (! is_array($values)) {
                continue;
            }

            $ids = [];
            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $id = trim((string) $value);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        return null;
    }

    /**
     * Graph multiple-ID read. Deleted/inaccessible individual creatives are
     * skipped; a top-level provider/auth/rate-limit failure still fails the run.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function getNodesByIds(CoreIntegration $integration, array $ids, string $fields): array
    {
        $token = $this->compatBroker->accessTokenFor($integration)->reveal();
        if ($token === '') {
            throw new MetaException(
                'Meta access token is not configured.',
                kind: MetaException::KIND_CONFIG,
            );
        }

        $query = [
            'ids' => implode(',', $ids),
            'fields' => $fields,
        ];

        if ((bool) config('moxdop.meta.use_appsecret_proof', true)) {
            $proof = $this->compatCredentials->appSecretProof($integration, $token);
            if ($proof !== null) {
                $query['appsecret_proof'] = $proof;
            }
        }

        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson()
                ->get(MetaApiConfig::graphBaseUrl(), $query);
        } catch (ConnectionException $exception) {
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }

        $status = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($status === 401) {
            throw new MetaException('Authentication failed.', MetaException::KIND_AUTH, $status, $this->providerCode($payload));
        }
        if ($status === 403) {
            throw new MetaException('Permission missing.', MetaException::KIND_PERMISSION, $status, $this->providerCode($payload));
        }
        if ($status === 429) {
            throw new MetaException('Rate limited.', MetaException::KIND_RATE_LIMIT, $status, $this->providerCode($payload));
        }
        if ($status >= 500) {
            throw new MetaException('Provider unavailable.', MetaException::KIND_HTTP, $status, $this->providerCode($payload));
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $code = is_numeric($payload['error']['code'] ?? null) ? (int) $payload['error']['code'] : null;
            $kind = match (true) {
                in_array($code, [190, 102], true) => MetaException::KIND_AUTH,
                in_array($code, [10, 200, 294], true) => MetaException::KIND_PERMISSION,
                in_array($code, [4, 17, 32, 613], true) => MetaException::KIND_RATE_LIMIT,
                default => MetaException::KIND_PROVIDER,
            };
            $message = trim((string) ($payload['error']['message'] ?? 'Meta Graph error.'));
            $message = preg_replace('/\s+/', ' ', $message) ?: 'Meta Graph error.';

            throw new MetaException(mb_substr($message, 0, 800), $kind, $status, $code);
        }

        if ($status >= 400) {
            throw new MetaException('Provider unavailable.', MetaException::KIND_HTTP, $status, $this->providerCode($payload));
        }

        $rows = [];
        foreach ($ids as $id) {
            $node = $payload[$id] ?? null;
            if (! is_array($node) || isset($node['error'])) {
                continue;
            }
            $rows[] = $node;
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private function providerCode(array $payload): ?int
    {
        $code = data_get($payload, 'error.code');

        return is_numeric($code) ? (int) $code : null;
    }
}
