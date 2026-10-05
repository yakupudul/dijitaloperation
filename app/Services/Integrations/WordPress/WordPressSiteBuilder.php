<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Connector 1.8.0 site building for Claude (MCP). A site is built only while both switches are on: "Site building" in
 * the plugin settings on the site, and "Claude site kurulumu" on the site in MoxDOP (an Admin turns it on; that Admin
 * is recorded as the approver of every build). Each build call is one ExternalWriteAction row (not undoable from
 * MoxDOP; the plugin's `trash` operation removes what it built).
 */
final class WordPressSiteBuilder
{
    public function __construct(private readonly WordPressConnectorClient $client) {}

    /**
     * Paired WordPress sites with their build state.
     *
     * @return list<array{site_id: int, name: string, brand: ?string, url: ?string, plugin_version: ?string, build_switch: bool, plugin_allows_build: bool, ready: bool, reason: ?string}>
     */
    public function sites(): array
    {
        return $this->connections()->map(function (CoreConnection $connection): array {
            $state = $this->state($connection);

            return [
                'site_id' => (int) $connection->digital_asset_id,
                'name' => (string) $connection->digitalAsset?->name,
                'brand' => $connection->digitalAsset?->brand?->name,
                'url' => $connection->digitalAsset?->primary_url ?: $connection->digitalAsset?->domain,
                'plugin_version' => data_get($connection->config, 'plugin_version'),
                'build_switch' => self::switchedOn($connection),
                'plugin_allows_build' => in_array('build', (array) data_get($connection->config, 'capabilities', []), true),
                'ready' => $state === null,
                'reason' => $state,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function inspect(int $siteId): array
    {
        return $this->client->buildInspect($this->connection($siteId));
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{action_id: int, status: string, results: list<array<string, mixed>>}
     */
    public function build(int $siteId, array $operations): array
    {
        $connection = $this->connection($siteId);
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_BUILD,
            'digital_asset_id' => $siteId, 'brand_id' => $connection->digitalAsset?->brand_id, 'status' => 'running', 'started_at' => now(),
            'request_payload' => ['label' => 'Claude site kurulumu', 'operations' => array_map(self::withoutFileData(...), $operations)],
            'requested_by' => (int) data_get($connection->config, 'claude_build.by'),
        ]);
        try {
            $results = array_values((array) ($this->client->build($connection, $operations)['results'] ?? []));
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();

            throw $exception;
        }
        $ok = count(array_filter($results, fn (mixed $result): bool => (bool) data_get($result, 'ok')));
        $status = $ok === count($results) ? 'succeeded' : ($ok > 0 ? 'partial' : 'failed');
        $action->forceFill(['status' => $status, 'result' => ['results' => $results], 'finished_at' => now()])->save();

        return ['action_id' => $action->id, 'status' => $status, 'results' => $results];
    }

    /** An Admin turns Claude site building on or off for one site. */
    public function setSwitch(DigitalAsset $site, User $user, bool $enabled): void
    {
        abort_unless($user->is_active && $user->hasRole(Roles::ADMIN), 403, 'Site kurulumunu yalnız Admin açıp kapatabilir.');
        $connection = $this->connections()->firstWhere('digital_asset_id', $site->id);
        if ($connection === null) {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $config = (array) $connection->config;
        $config['claude_build'] = $enabled ? ['enabled' => true, 'by' => $user->id, 'at' => now()->toIso8601String()] : ['enabled' => false];
        $connection->forceFill(['config' => $config])->save();
    }

    public static function switchedOn(?CoreConnection $connection): bool
    {
        return $connection !== null && (bool) data_get($connection->config, 'claude_build.enabled', false) && (int) data_get($connection->config, 'claude_build.by') > 0;
    }

    private function connection(int $siteId): CoreConnection
    {
        $connection = $this->connections()->firstWhere('digital_asset_id', $siteId);
        if ($connection === null) {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $reason = $this->state($connection);
        if ($reason !== null && self::switchedOn($connection) && ! $this->pluginReady($connection)) {
            // The stored version / capabilities may be older than the site: ask the site once before refusing.
            try {
                $this->client->status($connection);
            } catch (Throwable) {
            }
            $connection->refresh();
            $reason = $this->state($connection);
        }
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return $connection;
    }

    /** Why this site cannot be built now, or null when it can. */
    private function state(CoreConnection $connection): ?string
    {
        if (! (bool) config('moxdop-external-writes.enabled', true) || ! (bool) config('moxdop-external-writes.wordpress.enabled', true)) {
            return 'Harici yazma kapalı (EXTERNAL_WRITES_ENABLED).';
        }
        if (! self::switchedOn($connection)) {
            return 'Bu sitede "Claude site kurulumu" kapalı. MoxDOP › Entegrasyonlar › WordPress bağlayıcısı ekranından aç.';
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.build_min_plugin_version', '1.8.0');
        if (version_compare($version, $minimum, '<')) {
            return 'Sitedeki eklenti '.$version.'; site kurulumu için en az '.$minimum.' gerekli. Eklentiyi güncelle.';
        }
        if (! in_array('build', (array) data_get($connection->config, 'capabilities', []), true)) {
            return 'Sitedeki eklentide "Site building" kapalı. WordPress › Ayarlar › MoxDOP Connector ekranından aç.';
        }

        return null;
    }

    private function pluginReady(CoreConnection $connection): bool
    {
        return version_compare((string) data_get($connection->config, 'plugin_version', '0.0.0'), (string) config('moxdop-wordpress.build_min_plugin_version', '1.8.0'), '>=')
            && in_array('build', (array) data_get($connection->config, 'capabilities', []), true);
    }

    /** @return Collection<int, CoreConnection> */
    private function connections(): Collection
    {
        return CoreConnection::query()->with(['credential', 'digitalAsset.brand'])
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)
            ->where('config->pairing_state', 'paired')
            ->orderBy('id')
            ->get();
    }

    /**
     * Stored request without file bytes (base64 images are only counted).
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private static function withoutFileData(array $operation): array
    {
        if (isset($operation['data_base64']) && is_string($operation['data_base64'])) {
            $operation['data_base64'] = '['.strlen($operation['data_base64']).' bayt base64]';
        }

        return $operation;
    }
}
