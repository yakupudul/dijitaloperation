<?php

namespace App\Services\Assistant;

use App\Models\AssetAlert;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use App\Services\Website\PageFetcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uptime of client websites: the primary URL is fetched through the safe public fetcher every 5 minutes
 * (one queued job per site). A site is "down" after N consecutive failures (default 2 → ~10 minutes); going
 * down and coming back each send one phone push and open / resolve the website's `site_down` alert. A failure counts
 * only when the other spelling of the host (www / bare) fails too and the site's connector has not asked for work lately.
 * Before a site is called down (and while it stays down) an outside service is asked too ({@see OutsideReachCheck}):
 * when the site answers there, only MoxDOP's own server cannot reach it (a hosting firewall dropping MoxDOP's address),
 * so no alert or push is raised and an open alert closes quietly. No outside opinion = MoxDOP's own verdict stands.
 */
final class UptimeMonitor
{
    /** A connector that fetched work this recently proves the site is up even when our own request failed. */
    private const ASKED_LATELY_MINUTES = 6;

    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly PushNotifier $push,
        private readonly OutsideReachCheck $outside,
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
        if (! $ok && ($other = self::otherHost($url)) !== null) {
            // The stored address may be the host that only redirects (moximu.com → www.moximu.com): one slow answer there
            // is not an outage, so the other spelling is asked before the failure counts.
            try {
                $second = $this->fetcher->fetch($other);
            } catch (Throwable) {
                $second = ['status_code' => null];
            }
            $secondStatus = $second['status_code'] ?? null;
            if ($secondStatus !== null && $secondStatus >= 200 && $secondStatus < 400) {
                [$result, $status, $ok] = [$second, $secondStatus, true];
            }
        }
        $error = $ok ? null : mb_substr((string) ($result['error'] ?? ('HTTP '.$status)), 0, 500);
        if (! $ok && $this->siteAskedLately($site)) {
            // The site's own connector fetched work from MoxDOP minutes ago: the site is up, only our request to it failed
            // (firewall, rate limit). Counted as reachable; the check row keeps the error for the record.
            $ok = true;
        }
        $state = DB::table('uptime_states')->where('digital_asset_id', $site->id)->first();
        $previous = (string) ($state->state ?? 'unknown');
        $threshold = max(1, (int) config('moxdop-assistant.uptime.failures_before_down', 2));
        $seenFromOutside = false;
        if (! $ok && $url !== '' && ($previous === 'down' || (int) ($state->consecutive_failures ?? 0) + 1 >= $threshold)
            && config('moxdop-assistant.uptime.outside_check', true) && $this->outside->reachable($site->id, $url) === true) {
            // MoxDOP gets no answer, but the site answers outside (2026-11-14: six sites on the same Turkish hosting
            // timed out from MoxDOP at once while they were open for everyone): the host drops MoxDOP's address, the site is up.
            [$ok, $seenFromOutside] = [true, true];
            $error = mb_substr('Dışarıdan açık, MoxDOP sunucusundan ulaşılamadı: '.$error, 0, 500);
        }
        DB::table('uptime_checks')->insert([
            'digital_asset_id' => $site->id, 'url' => mb_substr($url, 0, 1000), 'ok' => $ok, 'status_code' => $status,
            'response_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error, 'checked_at' => now(),
        ]);

        $failures = $ok ? 0 : (int) ($state->consecutive_failures ?? 0) + 1;
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
            AssetAlert::query()->open()->where('digital_asset_id', $site->id)->where('kind', 'site_down')->update(['resolved_at' => now()]);
        }
        if ($new === 'up' && $previous === 'down' && ! $seenFromOutside) {
            // A site that only MoxDOP could not reach was never down for visitors: its alert closes without a second push.
            $minutes = $state?->down_since !== null ? (int) now()->diffInMinutes($state->down_since, true) : null;
            $this->push->send('uptime:up:'.$site->id.':'.now()->format('YmdHi'), 'Site yeniden açık: '.($site->domain ?: $site->name), $url.' tekrar yanıt veriyor'.($minutes !== null ? ' (kesinti ~'.$minutes.' dk).' : '.'), 'critical', $url, 1);
        }

        return ['ok' => $ok, 'state' => $new, 'changed' => $changed];
    }

    /** The same address with or without "www.", or null when the address has no host to toggle. */
    private static function otherHost(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $other = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        $at = strpos($url, $host);

        return $at === false || ! str_contains($other, '.') ? null : substr_replace($url, $other, $at, strlen($host));
    }

    private function siteAskedLately(DigitalAsset $site): bool
    {
        $connection = CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->first();
        if ($connection === null) {
            return false;
        }
        $seen = WordPressConnectorCommands::seenAt($connection);

        return $seen !== null && $seen->gte(now()->subMinutes(self::ASKED_LATELY_MINUTES));
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
