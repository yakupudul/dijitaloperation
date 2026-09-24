<?php

namespace App\Services\Operations;

use App\Enums\Observability\OperationalAlertState;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Observability\OperationalAlert;
use App\Models\Observability\OpsDispatcherHeartbeat;
use App\Models\Observability\WorkerHeartbeat;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One read of "is the machine working": scheduler and worker heartbeats, open operational alerts, each
 * integration's authorization state and expiry, every account's collection state and data freshness, and
 * WordPress connector plugin versions. Stored state only; no provider calls.
 */
final class SystemHealthReader
{
    /**
     * @return array{scheduler: array<string, mixed>, workers: list<array<string, mixed>>, alerts: list<array<string, mixed>>, integrations: list<array<string, mixed>>, accounts: list<array<string, mixed>>, account_counts: array<string, int>, plugins: list<array<string, mixed>>, plugin_current: string}
     */
    public function read(): array
    {
        $accounts = $this->accounts();

        return [
            'scheduler' => $this->scheduler(),
            'workers' => $this->workers(),
            'alerts' => $this->alerts(),
            'integrations' => $this->integrations(),
            'accounts' => $accounts,
            'account_counts' => [
                'total' => count($accounts),
                'attention' => count(array_filter($accounts, fn (array $a): bool => $a['state'] === 'attention')),
                'stale' => count(array_filter($accounts, fn (array $a): bool => $a['stale'])),
            ],
            'plugins' => $this->plugins(),
            'plugin_current' => (string) config('moxdop-wordpress.connector_version', ''),
            'backup' => Schema::hasTable('system_backups') ? app(SystemBackup::class)->status() : null,
            'two_factor' => $this->twoFactor(),
        ];
    }

    /** @return array{last_seen_at: ?string, minutes: ?int, ok: bool} */
    private function scheduler(): array
    {
        $last = OpsDispatcherHeartbeat::query()->max('last_seen_at');
        $minutes = $last !== null ? (int) CarbonImmutable::parse((string) $last)->diffInMinutes(now()) : null;

        // moxdop:ops:evaluate-alerts writes it every 5 minutes.
        return ['last_seen_at' => $last !== null ? (string) $last : null, 'minutes' => $minutes, 'ok' => $minutes !== null && $minutes <= 15];
    }

    /** @return list<array{name: string, last_seen_at: string, minutes: int, ok: bool}> */
    private function workers(): array
    {
        $stale = max(3, (int) ceil((int) config('moxdop-observability.worker.heartbeat_stale_seconds', 180) / 60));

        return WorkerHeartbeat::query()->orderBy('worker_id')->limit(50)->get()
            ->map(function (WorkerHeartbeat $beat) use ($stale): array {
                $minutes = (int) $beat->last_seen_at->diffInMinutes(now());
                // Queue probes run every 5 minutes; allow two missed probes.
                $limit = str_starts_with((string) $beat->worker_id, 'queue:') ? max($stale, 12) : $stale;

                return ['name' => (string) $beat->worker_id, 'last_seen_at' => (string) $beat->last_seen_at, 'minutes' => $minutes, 'ok' => $minutes <= $limit];
            })->values()->all();
    }

    /** @return list<array{severity: string, title: string, summary: ?string, since: string, count: int}> */
    private function alerts(): array
    {
        return OperationalAlert::query()
            ->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value])
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 else 2 end")->orderByDesc('last_observed_at')->limit(50)->get()
            ->map(fn (OperationalAlert $alert): array => [
                'severity' => strtolower((string) ($alert->severity->value ?? $alert->severity)),
                'title' => (string) $alert->title,
                'summary' => $alert->summary,
                'since' => (string) ($alert->opened_at ?? $alert->first_observed_at),
                'count' => (int) $alert->observation_count,
            ])->values()->all();
    }

    /** @return list<array{provider: string, name: string, status: string, auth_status: string, expires_at: ?string, expires_in_days: ?int, last_error: ?string}> */
    private function integrations(): array
    {
        return CoreIntegration::query()->orderBy('provider')->get()
            ->map(function (CoreIntegration $integration): array {
                $config = is_array($integration->config) ? $integration->config : [];
                $expires = $config['refresh_token_expires_at'] ?? null;
                if ($expires === null && $integration->provider === 'meta') {
                    $expires = CoreIntegrationCredential::query()->where('integration_id', $integration->id)
                        ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)->value('expires_at');
                }
                $expiresAt = filled($expires) ? CarbonImmutable::parse((string) $expires) : null;

                return [
                    'provider' => (string) $integration->provider,
                    'name' => (string) $integration->name,
                    'status' => (string) $integration->status,
                    'auth_status' => (string) ($config['auth_status'] ?? ''),
                    'expires_at' => $expiresAt?->toDateString(),
                    'expires_in_days' => $expiresAt !== null ? (int) round(now()->diffInDays($expiresAt, false)) : null,
                    'last_error' => filled($integration->last_error) ? mb_substr((string) $integration->last_error, 0, 200) : null,
                ];
            })->values()->all();
    }

    /** @return list<array{id: int, provider: string, type: string, name: string, enabled: bool, state: string, error: ?string, data_through: ?string, last_success: ?string, next: ?string, stale: bool}> */
    private function accounts(): array
    {
        $staleDays = (int) config('moxdop-observability.account_stale_days', 3);

        return ResourceAutomation::query()->with('resource')->get()
            ->filter(fn (ResourceAutomation $a): bool => $a->resource !== null)
            ->map(function (ResourceAutomation $a) use ($staleDays): array {
                $last = $a->last_collection_success_at;
                $interval = max(1, (int) ($a->interval_days ?? 1));

                return [
                    'id' => (int) $a->id,
                    'provider' => (string) $a->resource->provider,
                    'type' => (string) $a->resource->resource_type,
                    'name' => (string) ($a->resource->display_name ?: $a->resource->external_id),
                    'enabled' => (bool) $a->collection_enabled,
                    'state' => (string) ($a->collection_status ?: 'waiting'),
                    'error' => $a->collection_error,
                    'data_through' => $a->data_through !== null ? substr((string) $a->data_through, 0, 10) : null,
                    'last_success' => $last !== null ? (string) $last : null,
                    'next' => $a->next_collection_at !== null ? (string) $a->next_collection_at : null,
                    'stale' => (bool) $a->collection_enabled && ($last === null || CarbonImmutable::parse((string) $last)->lt(now()->subDays($interval + $staleDays))),
                ];
            })
            ->sortBy(fn (array $a): string => ($a['state'] === 'attention' ? '0' : ($a['stale'] ? '1' : '2')).$a['provider'].$a['name'])
            ->values()->all();
    }

    /** @return list<array{site: string, version: ?string, outdated: bool, last_received: ?string, silent: bool}> */
    private function plugins(): array
    {
        if (! Schema::hasTable('website_connector_delivery')) {
            return [];
        }
        $current = (string) config('moxdop-wordpress.connector_version', '');

        return DB::table('website_connector_delivery as d')
            ->join('core_connections as c', 'c.id', '=', 'd.connection_id')
            ->leftJoin('digital_assets as a', 'a.id', '=', 'c.digital_asset_id')
            ->where('c.type', 'wordpress_connector')
            ->orderBy('a.name')
            ->get(['a.name as site', 'a.domain', 'd.plugin_version', 'd.last_received_at'])
            ->map(fn (object $row): array => [
                'site' => (string) ($row->site ?: $row->domain ?: '—'),
                'version' => $row->plugin_version !== null ? (string) $row->plugin_version : null,
                'outdated' => self::isOutdated($row->plugin_version !== null ? (string) $row->plugin_version : null, $current),
                'last_received' => $row->last_received_at !== null ? (string) $row->last_received_at : null,
                'silent' => $row->last_received_at === null || CarbonImmutable::parse((string) $row->last_received_at)->lt(now()->subDay()),
            ])->values()->all();
    }

    public static function isOutdated(?string $installed, string $current): bool
    {
        return $current !== '' && ($installed === null || version_compare($installed, $current, '<'));
    }

    /**
     * Faz 11d: active admins without two-factor authentication, and whether it is enforced.
     *
     * @return array{enforced: bool, admins_without: list<string>}
     */
    private function twoFactor(): array
    {
        $without = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN))->get()
            ->reject(fn (User $user): bool => $user->hasTwoFactorEnabled())
            ->map(fn (User $user): string => (string) ($user->name ?: $user->email))->values()->all();

        return ['enforced' => (bool) config('moxdop.security.require_admin_2fa'), 'admins_without' => $without];
    }
}
