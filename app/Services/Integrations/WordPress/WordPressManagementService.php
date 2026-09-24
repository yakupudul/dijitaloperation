<?php

namespace App\Services\Integrations\WordPress;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * WordPress Connector v2 on the MoxDOP side (Faz 9c, ADR-068): daily health per paired site, one-click login into
 * the site admin (audited, admin-only) and the executor of approved updates. Login and updates also need the
 * site admin to switch them on inside the plugin.
 */
final class WordPressManagementService
{
    public function __construct(private readonly WordPressConnectorClient $client) {}

    /** Paired, enabled connector for a site with plugin ≥ management_min_plugin_version. */
    public function connection(int $siteId): CoreConnection
    {
        $connection = CoreConnection::query()->with('credential')->where('digital_asset_id', $siteId)
            ->where('type', 'wordpress_connector')->where('enabled', true)->first();
        if ($connection === null || data_get($connection->config, 'pairing_state') !== 'paired') {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.management_min_plugin_version', '1.3.0');
        if (version_compare($version, $minimum, '<')) {
            throw new RuntimeException('WordPress Connector '.$version.'; bu özellik için en az '.$minimum.' gerekli. Eklentiyi güncelle.');
        }

        return $connection;
    }

    /** @return array<string, mixed>|null latest health, null on failure (error stored) */
    public function refreshHealth(DigitalAsset $site): ?array
    {
        try {
            $data = $this->client->health($this->connection((int) $site->id));
            $pending = ($data['core_update'] ?? null) !== null ? 1 : 0;
            $pending += collect((array) ($data['plugins'] ?? []))->whereNotNull('update')->count();
            $pending += collect((array) ($data['themes'] ?? []))->whereNotNull('update')->count();
            DB::table('wordpress_site_health')->updateOrInsert(['digital_asset_id' => $site->id], [
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'pending_updates' => $pending,
                'critical_issues' => (int) data_get($data, 'site_health.critical', 0), 'error' => null, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $data;
        } catch (Throwable $exception) {
            DB::table('wordpress_site_health')->updateOrInsert(['digital_asset_id' => $site->id], ['error' => mb_substr($exception->getMessage(), 0, 500), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return null;
        }
    }

    /** @return array{checked: int, failed: int} */
    public function refreshAll(): array
    {
        $stats = ['checked' => 0, 'failed' => 0];
        $siteIds = CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)->whereNotNull('digital_asset_id')->pluck('digital_asset_id');
        foreach (DigitalAsset::query()->operational()->whereIn('id', $siteIds)->get() as $site) {
            $version = (string) data_get(CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->value('config'), 'plugin_version', '0.0.0');
            if (version_compare($version, (string) config('moxdop-wordpress.management_min_plugin_version', '1.3.0'), '<')) {
                continue;
            }
            $this->refreshHealth($site) !== null ? $stats['checked']++ : $stats['failed']++;
        }

        return $stats;
    }

    /** One-click login: admin only, audited, the plugin returns a single-use 60-second URL. */
    public function loginUrl(DigitalAsset $site, User $user): string
    {
        abort_unless($user->is_active && $user->hasRole(Roles::ADMIN), 403, 'WordPress paneline girişi yalnız Admin açabilir.');
        try {
            $data = $this->client->loginLink($this->connection((int) $site->id));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['login' => str_contains($exception->getMessage(), '403') || str_contains($exception->getMessage(), 'not enabled')
                ? 'Tek tık giriş bu sitede kapalı: site yöneticisi eklenti ayarlarından bir kullanıcı seçmeli.'
                : 'Giriş bağlantısı alınamadı: '.mb_substr($exception->getMessage(), 0, 200)]);
        }
        $url = (string) ($data['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        $siteHost = parse_url((string) data_get($this->connection((int) $site->id)->config, 'snapshot_url'), PHP_URL_HOST);
        if (! str_starts_with($url, 'https://') || $host === null || $host !== $siteHost) {
            throw ValidationException::withMessages(['login' => 'Eklenti beklenmeyen bir giriş adresi döndürdü.']);
        }
        $site->loadMissing('brand');
        app(SecurityAuditRecorder::class)->record(SecurityAuditEventKind::WordPressAdminLogin, $user, $site->brand?->customer_id, $site->brand_id, null, 'wordpress',
            'Tek tık WordPress girişi', ['digital_asset_id' => $site->id, 'wp_user' => $data['user'] ?? null]);

        return $url;
    }

    /** Executor for an approved update (ExternalWriteAction update_apply). */
    public function apply(ExternalWriteAction $action): array
    {
        $payload = (array) $action->request_payload;
        $result = $this->client->applyUpdate($this->connection((int) $action->digital_asset_id), (string) $payload['type'], (string) ($payload['item'] ?? ''));
        $site = DigitalAsset::query()->find($action->digital_asset_id);
        if ($site !== null) {
            $this->refreshHealth($site);
        }

        return $result + ['status' => ($result['ok'] ?? false) ? 'succeeded' : 'failed'];
    }
}
