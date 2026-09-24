<?php

namespace App\Services\Assistant;

use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Services\Demand\DemandPageFetcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uptime of client websites: the primary URL is fetched through the safe public fetcher every 5 minutes
 * (one queued job per site). A site is "down" after N consecutive failures (default 2 → ~10 minutes); going
 * down and coming back each send one phone push and open / resolve the website's `site_down` alert.
 */
final class UptimeMonitor
{
    public function __construct(
        private readonly DemandPageFetcher $fetcher,
        private readonly PushNotifier $push,
    ) {}

    /** @return array{ok: bool, state: string, changed: bool} */
    public function check(DigitalAsset $site): array
    {
        $url = (string) ($site->primary_url ?: ($site->domain ? 'https://'.$site->domain : ''));
        $started = microtime(true);
        try {
            $result = $url !== '' ? $this->fetcher->fetch($url) : ['status_code' => null, 'error' => 'no_url'];
        } catch (Throwable $exception) {
            $result = ['status_code' => null, 'error' => $exception->getMessage()];
        }
        $status = $result['status_code'] ?? null;
        $ok = $status !== null && $status >= 200 && $status < 400;
        $error = $ok ? null : mb_substr((string) ($result['error'] ?? ('HTTP '.$status)), 0, 500);
        DB::table('uptime_checks')->insert([
            'digital_asset_id' => $site->id, 'url' => mb_substr($url, 0, 1000), 'ok' => $ok, 'status_code' => $status,
            'response_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error, 'checked_at' => now(),
        ]);

        $state = DB::table('uptime_states')->where('digital_asset_id', $site->id)->first();
        $previous = (string) ($state->state ?? 'unknown');
        $failures = $ok ? 0 : (int) ($state->consecutive_failures ?? 0) + 1;
        $threshold = max(1, (int) config('moxdop-assistant.uptime.failures_before_down', 2));
        $new = $ok ? 'up' : ($failures >= $threshold ? 'down' : ($previous === 'down' ? 'down' : $previous));
        $downSince = $new === 'down' ? ($previous === 'down' ? $state->down_since : now()) : null;
        DB::table('uptime_states')->updateOrInsert(['digital_asset_id' => $site->id], [
            'state' => $new, 'consecutive_failures' => $failures, 'down_since' => $downSince,
            'last_checked_at' => now(), 'last_up_at' => $ok ? now() : ($state->last_up_at ?? null), 'last_error' => $error,
            'updated_at' => now(), 'created_at' => $state->created_at ?? now(),
        ]);

        $changed = $new !== $previous;
        if ($new === 'down' && $previous !== 'down') {
            $this->openAlert($site, $error);
            $this->push->send('uptime:down:'.$site->id, 'Site erişilemiyor: '.($site->domain ?: $site->name), ($site->brand?->name ?? '').' — '.$url.' yanıt vermiyor ('.$error.').', 'critical', $url, 1);
        }
        if ($new === 'up' && $previous === 'down') {
            $minutes = $state?->down_since !== null ? (int) now()->diffInMinutes($state->down_since, true) : null;
            AssetAlert::query()->open()->where('digital_asset_id', $site->id)->where('kind', 'site_down')->update(['resolved_at' => now()]);
            $this->push->send('uptime:up:'.$site->id.':'.now()->format('YmdHi'), 'Site yeniden açık: '.($site->domain ?: $site->name), $url.' tekrar yanıt veriyor'.($minutes !== null ? ' (kesinti ~'.$minutes.' dk).' : '.'), 'critical', $url, 1);
        }

        return ['ok' => $ok, 'state' => $new, 'changed' => $changed];
    }

    private function openAlert(DigitalAsset $site, ?string $error): void
    {
        $alert = AssetAlert::query()->firstOrNew(['digital_asset_id' => $site->id, 'alert_key' => hash('sha256', 'site_down')]);
        if (! $alert->exists || $alert->resolved_at !== null) {
            $alert->first_detected_at = now();
            $alert->resolved_at = null;
            $alert->snoozed_until = null;
            $alert->snoozed_by = null;
        }
        $alert->fill([
            'brand_id' => $site->brand_id, 'kind' => 'site_down', 'severity' => 'critical', 'title' => 'Site erişilemiyor',
            'message' => 'Ana sayfa art arda yanıt vermedi ('.($error ?? 'hata').'). Sunucu, alan adı veya SSL sorununu kontrol edin.',
            'data' => ['error' => $error], 'last_detected_at' => now(),
        ])->save();
    }
}
