<?php

namespace App\Services\Site\Health;

use App\Models\DigitalAsset;
use App\Services\Site\SiteDomains;
use App\Support\SslCertificateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSL certificate expiry (peer certificate over TLS, `SslCertificateProbe`) and domain expiry (RDAP "expiration"
 * event) of a website, stored in `website_expiry_checks`; checked at most once a day per site.
 */
final class SiteExpiryChecker
{
    public function __construct(private readonly SslCertificateProbe $tls) {}

    /** @return array{checked: bool, ssl_expires_at: ?string, domain_expires_at: ?string} */
    public function check(DigitalAsset $site, bool $force = false): array
    {
        $row = DB::table('website_expiry_checks')->where('website_asset_id', $site->id)->first();
        if (! $force && $row?->checked_at !== null && CarbonImmutable::parse($row->checked_at)->greaterThan(now()->subHours(20))) {
            return ['checked' => false, 'ssl_expires_at' => $row->ssl_expires_at, 'domain_expires_at' => $row->domain_expires_at];
        }
        $host = SiteDomains::host($site->domain) ?? SiteDomains::host($site->primary_url);
        $values = ['host' => $host, 'checked_at' => now(), 'updated_at' => now()];
        if ($host === null) {
            $values += ['ssl_expires_at' => null, 'ssl_issuer' => null, 'ssl_error' => 'Alan adı yok', 'registrable_domain' => null, 'domain_expires_at' => null, 'domain_error' => 'Alan adı yok'];
        } else {
            $values += $this->ssl($host) + $this->domain(SiteDomains::registrable($host));
        }
        DB::table('website_expiry_checks')->updateOrInsert(['website_asset_id' => $site->id], $values + ['created_at' => $row->created_at ?? now()]);

        return ['checked' => true, 'ssl_expires_at' => $values['ssl_expires_at'] ?? null, 'domain_expires_at' => $values['domain_expires_at'] ?? null];
    }

    /** @return array{checked: int} */
    public function checkAll(): array
    {
        $checked = 0;
        DigitalAsset::query()->operational()->where('type', 'website')->orderBy('id')
            ->each(function (DigitalAsset $site) use (&$checked): void {
                $checked += $this->check($site)['checked'] ? 1 : 0;
            }, 100);

        return ['checked' => $checked];
    }

    /** @return array{ssl_expires_at: ?CarbonImmutable, ssl_issuer: ?string, ssl_error: ?string} */
    private function ssl(string $host): array
    {
        try {
            $probe = $this->tls->probe($host, now());
        } catch (Throwable $error) {
            return ['ssl_expires_at' => null, 'ssl_issuer' => null, 'ssl_error' => mb_substr($error->getMessage(), 0, 240)];
        }
        $validTo = $probe['valid_to'] ?? null;
        if (! ($probe['present'] ?? false) || $validTo === null) {
            return ['ssl_expires_at' => null, 'ssl_issuer' => null, 'ssl_error' => match ($probe['error_class'] ?? null) {
                'certificate_missing' => 'Sertifika alınamadı',
                'certificate_unparseable' => 'Sertifika okunamadı',
                default => 'Sertifika yok',
            }];
        }

        return ['ssl_expires_at' => CarbonImmutable::parse($validTo), 'ssl_issuer' => $probe['issuer_common_name'] ?? null, 'ssl_error' => null];
    }

    /** @return array{registrable_domain: string, domain_expires_at: ?CarbonImmutable, domain_error: ?string} */
    private function domain(string $domain): array
    {
        $base = rtrim((string) config('moxdop-site.health.rdap_base_url', 'https://rdap.org'), '/');
        try {
            $response = Http::timeout(15)->acceptJson()->get($base.'/domain/'.rawurlencode($domain));
            if ($response->status() === 404) {
                return ['registrable_domain' => $domain, 'domain_expires_at' => null, 'domain_error' => 'RDAP kaydı yok (uzantı desteklenmiyor olabilir)'];
            }
            if (! $response->successful()) {
                return ['registrable_domain' => $domain, 'domain_expires_at' => null, 'domain_error' => 'RDAP HTTP '.$response->status()];
            }
            $expires = self::expiration((array) $response->json());

            return ['registrable_domain' => $domain, 'domain_expires_at' => $expires, 'domain_error' => $expires === null ? 'RDAP bitiş tarihi vermiyor' : null];
        } catch (Throwable $error) {
            return ['registrable_domain' => $domain, 'domain_expires_at' => null, 'domain_error' => 'RDAP: '.mb_substr($error->getMessage(), 0, 200)];
        }
    }

    /** @param  array<string, mixed>  $rdap */
    public static function expiration(array $rdap): ?CarbonImmutable
    {
        foreach ((array) ($rdap['events'] ?? []) as $event) {
            if (is_array($event) && in_array(mb_strtolower((string) ($event['eventAction'] ?? '')), ['expiration', 'registration expiration'], true) && filled($event['eventDate'] ?? null)) {
                try {
                    return CarbonImmutable::parse((string) $event['eventDate']);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }
}
