<?php

namespace App\Services\Site\Health;

use App\Models\DigitalAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site Sağlığı rows (durum · tarih · tek satır): WordPress core / plugin / theme updates and critical Site Health items
 * (Connector health snapshot), SSL and domain expiry (daily check), hosting expiry (manual date), uptime (monitor state).
 * State: ok · warn · critical · unknown.
 */
final class SiteHealthReader
{
    /** @return list<array{key: string, label: string, state: string, date: ?string, line: string}> */
    public function rows(DigitalAsset $site): array
    {
        $warnDays = (int) config('moxdop-site.health.warn_days', 30);
        $rows = [...$this->wordpress($site)];
        $expiry = Schema::hasTable('website_expiry_checks') ? DB::table('website_expiry_checks')->where('website_asset_id', $site->id)->first() : null;
        $rows[] = self::expiryRow('ssl', 'SSL sertifikası', $expiry?->ssl_expires_at, $expiry?->ssl_error, $expiry === null, $warnDays,
            filled($expiry?->ssl_issuer) ? (string) $expiry->ssl_issuer : null);
        $rows[] = self::expiryRow('domain', 'Alan adı', $expiry?->domain_expires_at, $expiry?->domain_error, $expiry === null, $warnDays,
            filled($expiry?->registrable_domain) ? (string) $expiry->registrable_domain : null);
        $hosting = $site->getAttribute('hosting_expires_on');
        $rows[] = $hosting === null
            ? ['key' => 'hosting', 'label' => 'Hosting', 'state' => 'unknown', 'date' => null, 'line' => 'Bitiş tarihi girilmedi']
            : self::expiryRow('hosting', 'Hosting', (string) $hosting, null, false, $warnDays, 'elle girildi');
        $rows[] = $this->uptime($site);

        return $rows;
    }

    /** @return list<array{key: string, label: string, state: string, date: ?string, line: string}> */
    private function wordpress(DigitalAsset $site): array
    {
        if (! Schema::hasTable('wordpress_site_health')) {
            return [];
        }
        $row = DB::table('wordpress_site_health')->where('digital_asset_id', $site->id)->first();
        if ($row === null) {
            return [];
        }
        $date = $row->checked_at !== null ? CarbonImmutable::parse($row->checked_at)->format('d.m.Y') : null;
        if (filled($row->error) && ! filled($row->payload)) {
            return [['key' => 'wp', 'label' => 'WordPress', 'state' => 'unknown', 'date' => $date, 'line' => mb_substr((string) $row->error, 0, 160)]];
        }
        $health = (array) json_decode((string) $row->payload, true);
        $out = [];
        if (filled($health['core_update'] ?? null)) {
            $out[] = ['key' => 'wp_core', 'label' => 'WordPress', 'state' => 'warn', 'date' => $date, 'line' => ($health['wordpress_version'] ?? '?').' → '.$health['core_update']];
        }
        foreach (['plugins' => 'Eklenti', 'themes' => 'Tema'] as $group => $label) {
            foreach ((array) ($health[$group] ?? []) as $item) {
                if (is_array($item) && filled($item['update'] ?? null)) {
                    $out[] = ['key' => 'wp_'.$group.'_'.md5((string) ($item['file'] ?? $item['stylesheet'] ?? $item['name'] ?? '')), 'label' => $label.': '.($item['name'] ?? '?'),
                        'state' => 'warn', 'date' => $date, 'line' => ($item['version'] ?? '?').' → '.$item['update'].(($item['active'] ?? true) ? '' : ' · pasif')];
                }
            }
        }
        $critical = (int) data_get($health, 'site_health.critical', 0);
        $out[] = $critical > 0
            ? ['key' => 'wp_health', 'label' => 'Site Sağlığı', 'state' => 'critical', 'date' => $date, 'line' => $critical.' kritik sorun · WordPress › Araçlar › Site Sağlığı']
            : ['key' => 'wp_health', 'label' => 'Site Sağlığı', 'state' => 'ok', 'date' => $date, 'line' => $out === [] ? 'Güncelleme yok · kritik sorun yok' : 'Kritik sorun yok'];

        return $out;
    }

    /** @return array{key: string, label: string, state: string, date: ?string, line: string} */
    public static function expiryRow(string $key, string $label, ?string $expiresAt, ?string $error, bool $notChecked, int $warnDays, ?string $detail): array
    {
        if ($expiresAt === null) {
            return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'date' => null, 'line' => $notChecked ? 'Henüz kontrol edilmedi' : (string) ($error ?: 'Tarih yok')];
        }
        $date = CarbonImmutable::parse($expiresAt);
        $days = (int) floor(now()->startOfDay()->diffInDays($date->startOfDay(), false));
        $state = $days < 0 ? 'critical' : ($days <= $warnDays ? 'warn' : 'ok');
        $line = $days < 0 ? abs($days).' gün önce doldu' : $days.' gün kaldı';

        return ['key' => $key, 'label' => $label, 'state' => $state, 'date' => $date->format('d.m.Y'), 'line' => $line.($detail !== null ? ' · '.$detail : '')];
    }

    /** @return array{key: string, label: string, state: string, date: ?string, line: string} */
    private function uptime(DigitalAsset $site): array
    {
        $state = Schema::hasTable('uptime_states') ? DB::table('uptime_states')->where('digital_asset_id', $site->id)->first() : null;
        if ($state === null) {
            return ['key' => 'uptime', 'label' => 'Erişilebilirlik', 'state' => 'unknown', 'date' => null, 'line' => 'Henüz ölçülmedi'];
        }
        $checked = $state->last_checked_at !== null ? CarbonImmutable::parse($state->last_checked_at)->format('d.m.Y H:i') : null;

        return match ((string) $state->state) {
            'up' => ['key' => 'uptime', 'label' => 'Erişilebilirlik', 'state' => 'ok', 'date' => $checked, 'line' => 'Açık'],
            'down' => ['key' => 'uptime', 'label' => 'Erişilebilirlik', 'state' => 'critical', 'date' => $checked,
                'line' => 'Erişilemiyor'.($state->down_since !== null ? ' · '.CarbonImmutable::parse($state->down_since)->format('d.m.Y H:i').' itibarıyla' : '').(filled($state->last_error) ? ' · '.mb_substr((string) $state->last_error, 0, 80) : '')],
            default => ['key' => 'uptime', 'label' => 'Erişilebilirlik', 'state' => 'unknown', 'date' => $checked, 'line' => 'Bilinmiyor'],
        };
    }
}
