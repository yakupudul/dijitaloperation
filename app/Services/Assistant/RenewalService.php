<?php

namespace App\Services\Assistant;

use App\Models\AssetRenewal;
use App\Models\DigitalAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Renewals: every operational website gets a domain and an SSL row automatically. Domain expiry and registrar
 * come from public RDAP (refreshed weekly), SSL expiry from the collected TLS certificate; manual dates are
 * never overwritten. Hosting and other renewals are entered by hand. Due renewals raise a website alert
 * (see AssetAlertScanner) and a phone push at 30 / 14 / 7 / 1 days.
 */
final class RenewalService
{
    public function __construct(private readonly PushNotifier $push) {}

    /** @return array{sites: int, domains_refreshed: int, ssl_refreshed: int, pushed: int} */
    public function daily(): array
    {
        $stats = ['sites' => 0, 'domains_refreshed' => 0, 'ssl_refreshed' => 0, 'pushed' => 0];
        foreach (DigitalAsset::query()->operational()->where('type', 'website')->get() as $site) {
            $stats['sites']++;
            $stats['domains_refreshed'] += $this->syncDomain($site) ? 1 : 0;
            $stats['ssl_refreshed'] += $this->syncSsl($site) ? 1 : 0;
        }
        $stats['pushed'] = $this->notifyDue();

        return $stats;
    }

    public function syncDomain(DigitalAsset $site): bool
    {
        $domain = $this->registrableDomain((string) ($site->domain ?: parse_url((string) $site->primary_url, PHP_URL_HOST)));
        if ($domain === '' || $site->brand_id === null) {
            return false;
        }
        $row = AssetRenewal::query()->firstOrCreate(
            ['digital_asset_id' => $site->id, 'kind' => 'domain'],
            ['brand_id' => $site->brand_id, 'label' => $domain, 'expires_source' => 'rdap'],
        );
        $fresh = $row->last_checked_at !== null && $row->last_checked_at->gt(now()->subDays((int) config('moxdop-assistant.renewals.rdap_refresh_days', 7)));
        if ($row->expires_source === 'manual' || $fresh) {
            return false;
        }
        try {
            $response = Http::timeout(15)->acceptJson()->get(rtrim((string) config('moxdop-assistant.renewals.rdap_bootstrap'), '/').'/'.$domain);
            $row->last_checked_at = now();
            if ($response->successful()) {
                $data = (array) $response->json();
                $expiry = collect((array) ($data['events'] ?? []))->firstWhere('eventAction', 'expiration')['eventDate'] ?? null;
                if (is_string($expiry)) {
                    $row->expires_on = CarbonImmutable::parse($expiry)->toDateString();
                }
                $registrar = $this->registrar($data);
                if ($registrar !== null && blank($row->provider)) {
                    $row->provider = $registrar;
                }
            }
            $row->save();

            return $response->successful();
        } catch (Throwable $exception) {
            report($exception);
            $row->forceFill(['last_checked_at' => now()])->save();

            return false;
        }
    }

    public function syncSsl(DigitalAsset $site): bool
    {
        if ($site->brand_id === null || ! Schema::hasTable('website_infra_snapshot')) {
            return false;
        }
        $metadata = DB::table('website_infra_snapshot')->where('digital_asset_id', $site->id)->orderByDesc('observed_at')->value('metadata');
        $validTo = data_get(json_decode((string) $metadata, true), 'tls.valid_to');
        if (! is_string($validTo) || $validTo === '') {
            return false;
        }
        $row = AssetRenewal::query()->firstOrCreate(
            ['digital_asset_id' => $site->id, 'kind' => 'ssl'],
            ['brand_id' => $site->brand_id, 'label' => 'SSL · '.($site->domain ?: $site->name), 'expires_source' => 'tls', 'collection_status' => 'not_charged'],
        );
        if ($row->expires_source === 'manual') {
            return false;
        }
        $issuer = data_get(json_decode((string) $metadata, true), 'tls.issuer_common_name');
        $row->fill([
            'expires_on' => CarbonImmutable::parse($validTo)->toDateString(), 'last_checked_at' => now(),
            'provider' => $row->provider ?: (is_string($issuer) ? mb_substr($issuer, 0, 160) : null),
            // Free automated certificates renew themselves; keep them visible but quieter.
            'auto_renew' => $row->auto_renew || (is_string($issuer) && preg_match("/let's encrypt|^R\\d+$|^E\\d+$|zerossl|cloudflare|google trust/i", $issuer) === 1),
        ])->save();

        return true;
    }

    /** Phone push when a renewal crosses 30 / 14 / 7 / 1 days (once per threshold) or has expired. */
    public function notifyDue(): int
    {
        $thresholds = (array) config('moxdop-assistant.renewals.warn_days', [30, 14, 7, 1]);
        $sent = 0;
        AssetRenewal::query()->with('brand:id,name')->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(max($thresholds)))->get()
            ->each(function (AssetRenewal $renewal) use ($thresholds, &$sent): void {
                $days = $renewal->daysLeft();
                if ($days === null || ($renewal->auto_renew && $days > 7)) {
                    return;
                }
                $threshold = $days < 0 ? 'expired' : collect($thresholds)->filter(fn (int $t): bool => $days <= $t)->min();
                $when = $days < 0 ? 'süresi '.abs($days).' gün önce doldu' : ($days === 0 ? 'bugün bitiyor' : $days.' gün sonra bitiyor');
                $fee = $renewal->charge_amount !== null ? ' Müşteriye: '.number_format($renewal->charge_amount, 0, ',', '.').' '.$renewal->currency.' ('.(AssetRenewal::COLLECTION[$renewal->collection_status] ?? $renewal->collection_status).').' : '';
                $sent += $this->push->send('renewal:'.$renewal->id.':'.$threshold.':'.$renewal->expires_on?->toDateString(),
                    (AssetRenewal::KINDS[$renewal->kind] ?? 'Yenileme').' yenilemesi: '.$renewal->label,
                    ($renewal->brand?->name ?? '').' — '.$when.'.'.$fee, $days <= 7 ? 'high' : 'medium', null, 24 * 40) > 0 ? 1 : 0;
            });

        return $sent;
    }

    /** example.com.tr stays whole; www. and sub-domains are dropped. */
    public function registrableDomain(string $host): string
    {
        $host = strtolower(trim(preg_replace('/^www\./', '', $host) ?? ''));
        $parts = array_values(array_filter(explode('.', $host)));
        if (count($parts) < 2) {
            return '';
        }
        $secondLevel = ['com', 'net', 'org', 'gen', 'biz', 'info', 'av', 'dr', 'bel', 'edu', 'gov', 'k12', 'tv', 'web', 'tel', 'co'];
        $take = count($parts) >= 3 && in_array($parts[count($parts) - 2], $secondLevel, true) && strlen($parts[count($parts) - 1]) === 2 ? 3 : 2;

        return implode('.', array_slice($parts, -$take));
    }

    /** @param  array<string, mixed>  $rdap */
    private function registrar(array $rdap): ?string
    {
        foreach ((array) ($rdap['entities'] ?? []) as $entity) {
            if (in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                foreach ((array) data_get($entity, 'vcardArray.1', []) as $field) {
                    if (($field[0] ?? null) === 'fn' && is_string($field[3] ?? null)) {
                        return mb_substr($field[3], 0, 160);
                    }
                }
            }
        }

        return null;
    }
}
