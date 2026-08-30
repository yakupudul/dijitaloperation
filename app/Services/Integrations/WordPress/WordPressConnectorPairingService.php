<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\WordPress\WordPressConnectorUrlGuard;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WordPressConnectorPairingService
{
    public const string CONNECTION_TYPE = 'wordpress_connector';

    public const string PAIRING_PENDING = 'pairing_pending';

    public const string PAIRED = 'paired';

    public const string DISCONNECTED = 'disconnected';

    public function __construct(
        private readonly WordPressConnectorUrlGuard $urls = new WordPressConnectorUrlGuard,
    ) {}

    /**
     * @return array{connection: CoreConnection, code: string, expires_at: CarbonImmutable}
     */
    public function issue(DigitalAsset $asset, User $actor): array
    {
        if (! $actor->hasRole(Roles::ADMIN)) {
            throw new InvalidArgumentException('Only an Admin can manage WordPress connector credentials.');
        }
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('WordPress connector pairing requires a Website Digital Asset.');
        }

        return DB::transaction(function () use ($asset): array {
            DigitalAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $connection = CoreConnection::query()->firstOrCreate(
                [
                    'digital_asset_id' => $asset->id,
                    'type' => self::CONNECTION_TYPE,
                ],
                [
                    'name' => 'WordPress Connector',
                    'config' => [],
                    'enabled' => false,
                ],
            );

            $hasLiveCredential = $connection->credential()->exists();

            $random = strtoupper(Str::random(24));
            $random = str_replace(['0', '1', 'I', 'O'], ['2', '3', 'J', 'P'], $random);
            $code = 'MXD-'.$connection->id.'-'.$random;
            $expiresAt = CarbonImmutable::now('UTC')->addMinutes(
                max(5, (int) config('moxdop-wordpress.pairing_ttl_minutes', 15)),
            );

            $connection->forceFill([
                'name' => 'WordPress Connector',
                // A pending rotation must not interrupt an already paired site.
                'enabled' => $hasLiveCredential ? (bool) $connection->enabled : false,
                'config' => array_merge(is_array($connection->config) ? $connection->config : [], [
                    'pairing_state' => $hasLiveCredential ? self::PAIRED : self::PAIRING_PENDING,
                    'pairing_rotation_pending' => $hasLiveCredential,
                    'pairing_code_hash' => $this->codeHash($code),
                    'pairing_code_expires_at' => $expiresAt->toIso8601String(),
                ]),
            ])->save();

            return [
                'connection' => $connection->fresh() ?? $connection,
                'code' => $code,
                'expires_at' => $expiresAt,
            ];
        });
    }

    /**
     * @param  array{
     *   pairing_code:string,
     *   site_url:string,
     *   home_url:string,
     *   status_url:string,
     *   snapshot_url:string,
     *   installation_id:string,
     *   plugin_version:string
     * }  $payload
     * @return array{connection_id:int,client_id:string,shared_secret:string,paired_at:string}
     */
    public function complete(array $payload): array
    {
        $connectionId = $this->connectionIdFromCode($payload['pairing_code']);

        return DB::transaction(function () use ($payload, $connectionId): array {
            $connection = CoreConnection::query()
                ->with('digitalAsset')
                ->lockForUpdate()
                ->whereKey($connectionId)
                ->where('type', self::CONNECTION_TYPE)
                ->first();

            if (! $connection instanceof CoreConnection || ! $connection->digitalAsset instanceof DigitalAsset) {
                throw new InvalidArgumentException('Pairing code is invalid or expired.');
            }

            $config = is_array($connection->config) ? $connection->config : [];
            $expiresAt = CarbonImmutable::parse((string) ($config['pairing_code_expires_at'] ?? '1970-01-01'), 'UTC');
            $pairingPending = ($config['pairing_state'] ?? null) === self::PAIRING_PENDING
                || (($config['pairing_state'] ?? null) === self::PAIRED && ($config['pairing_rotation_pending'] ?? false) === true);
            if (! $pairingPending
                || $expiresAt->isPast()
                || ! hash_equals((string) ($config['pairing_code_hash'] ?? ''), $this->codeHash($payload['pairing_code']))) {
                throw new InvalidArgumentException('Pairing code is invalid or expired.');
            }

            $this->urls->assertMatchesAsset($connection->digitalAsset, [
                $payload['site_url'],
                $payload['home_url'],
                $payload['status_url'],
                $payload['snapshot_url'],
            ]);
            $this->urls->assertHttpsEndpoint($payload['status_url']);
            $this->urls->assertHttpsEndpoint($payload['snapshot_url']);
            $this->urls->assertConnectorEndpoint($payload['status_url'], 'status');
            $this->urls->assertConnectorEndpoint($payload['snapshot_url'], 'snapshot');

            $clientId = (string) Str::uuid();
            $sharedSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pairedAt = CarbonImmutable::now('UTC');

            CoreConnectionCredential::query()->updateOrCreate(
                ['connection_id' => $connection->id],
                ['encrypted_payload' => [
                    'client_id' => $clientId,
                    'shared_secret' => $sharedSecret,
                    'algorithm' => 'hmac-sha256',
                    'issued_at' => $pairedAt->toIso8601String(),
                ]],
            );

            unset($config['pairing_code_hash'], $config['pairing_code_expires_at'], $config['pairing_rotation_pending']);
            $connection->forceFill([
                'enabled' => true,
                'last_success_at' => $pairedAt,
                'last_error' => null,
                'config' => array_merge($config, [
                    'pairing_state' => self::PAIRED,
                    'site_url' => $payload['site_url'],
                    'home_url' => $payload['home_url'],
                    'status_url' => $payload['status_url'],
                    'snapshot_url' => $payload['snapshot_url'],
                    'installation_id' => $payload['installation_id'],
                    'plugin_version' => $payload['plugin_version'],
                    'paired_at' => $pairedAt->toIso8601String(),
                ]),
            ])->save();

            return [
                'connection_id' => (int) $connection->id,
                'client_id' => $clientId,
                'shared_secret' => $sharedSecret,
                'paired_at' => $pairedAt->toIso8601String(),
            ];
        });
    }

    public function revoke(DigitalAsset $asset, User $actor): void
    {
        if (! $actor->hasRole(Roles::ADMIN)) {
            throw new InvalidArgumentException('Only an Admin can manage WordPress connector credentials.');
        }
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('WordPress connector revocation requires a Website Digital Asset.');
        }

        DB::transaction(function () use ($asset, $actor): void {
            DigitalAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $connection = CoreConnection::query()
                ->where('digital_asset_id', $asset->id)
                ->where('type', self::CONNECTION_TYPE)
                ->lockForUpdate()
                ->first();

            if (! $connection instanceof CoreConnection) {
                return;
            }

            $connection->credential()->delete();
            $config = is_array($connection->config) ? $connection->config : [];
            unset($config['pairing_code_hash'], $config['pairing_code_expires_at'], $config['pairing_rotation_pending']);
            $connection->forceFill([
                'enabled' => false,
                'last_error' => null,
                'config' => array_merge($config, [
                    'pairing_state' => self::DISCONNECTED,
                    'disconnected_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'disconnected_by' => (int) $actor->id,
                ]),
            ])->save();
        });
    }

    private function connectionIdFromCode(string $code): int
    {
        if (! preg_match('/^MXD-(\d+)-[A-Z2-9]{24}$/', strtoupper(trim($code)), $matches)) {
            throw new InvalidArgumentException('Pairing code is invalid or expired.');
        }

        return (int) $matches[1];
    }

    private function codeHash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }
}
