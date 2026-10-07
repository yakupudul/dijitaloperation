<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandExpert;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\Page;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoStoredHtmlReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kimlik tutarlılığı (yakup, 2026-10-07, AI görünürlüğü 4. madde): search engines and AI assistants trust a business
 * whose name, phone, address and site are the same everywhere. By rules (no AI) the brand's Google Business Profile
 * listings are compared with its website (home page structured data and tel: links, home and contact page text):
 * name, phone, address, website link, the social profiles the site's schema lists (sameAs) and the expert author.
 * Nothing is written anywhere; each row says what differs and where it is fixed. Cached for 6 hours per brand.
 */
final class BrandIdentity
{
    public const int CACHE_HOURS = 6;

    /** Words of an address line that say nothing on their own. */
    private const array ADDRESS_NOISE = ['mah', 'mahallesi', 'mh', 'cad', 'caddesi', 'cd', 'sok', 'sokak', 'sk', 'sokagi', 'bulvari', 'blv', 'no', 'kat', 'daire', 'apt', 'apartmani', 'plaza', 'merkezi'];

    public function __construct(private readonly GbpDesk $gbp) {}

    public static function cacheKey(int $brandId): string
    {
        return 'brand-identity:v1:'.$brandId;
    }

    /**
     * @return array{rows: list<array{key: string, label: string, state: string, detail: string}>, same_as_missing: list<string>, checked_at: string}|null
     *                                                                                                                                                     null when the brand has neither a website nor a Business Profile
     */
    public function for(Brand $brand, bool $fresh = false): ?array
    {
        if ($fresh) {
            Cache::forget(self::cacheKey((int) $brand->id));
        }

        return Cache::remember(self::cacheKey((int) $brand->id), now()->addHours(self::CACHE_HOURS), fn (): ?array => $this->build($brand));
    }

    /** @return array{rows: list<array{key: string, label: string, state: string, detail: string}>, same_as_missing: list<string>, checked_at: string}|null */
    private function build(Brand $brand): ?array
    {
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('status', '!=', 'archived')->orderBy('id')->get(['id', 'type', 'status', 'name', 'domain', 'primary_url']);
        $site = $assets->first(fn (DigitalAsset $a): bool => $a->type === 'website' && $a->status === 'active') ?? $assets->firstWhere('type', 'website');
        $profiles = array_values($this->gbp->snapshots($assets->whereIn('type', ['gbp', 'google_business_profile'])->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        if ($site === null && $profiles === []) {
            return null;
        }
        $facts = $site !== null ? $this->siteFacts($site) : ['names' => [], 'phones' => [], 'text' => '', 'same_as' => [], 'read' => false];
        $siteHost = $site !== null ? self::host((string) ($site->primary_url ?: $site->domain)) : '';

        $rows = [
            $this->nameRow($brand, $profiles, $facts),
            $this->phoneRow($profiles, $facts),
            $this->addressRow($profiles, $facts),
            $this->websiteRow($profiles, $siteHost),
        ];
        $known = array_values(array_unique(array_filter([
            ...$assets->where('type', 'instagram')->map(fn (DigitalAsset $a): string => self::instagramUrl($a))->all(),
            ...array_column($profiles, 'maps_uri'),
        ])));
        $listed = array_map(self::urlKey(...), $facts['same_as']);
        $missing = array_values(array_filter($known, fn (string $url): bool => ! in_array(self::urlKey($url), $listed, true)));
        $rows[] = $this->sameAsRow($facts, $known, $missing);
        $rows[] = $this->expertRow((int) $brand->id);

        return ['rows' => array_values(array_filter($rows)), 'same_as_missing' => $missing, 'checked_at' => now()->toIso8601String()];
    }

    /**
     * Name, phone, address and sameAs of the site: JSON-LD and tel: links of the home page (stored crawl copy), plus
     * the text of the home and contact pages.
     *
     * @return array{names: list<string>, phones: list<string>, text: string, same_as: list<string>, read: bool}
     */
    private function siteFacts(DigitalAsset $site): array
    {
        $out = ['names' => [], 'phones' => [], 'text' => '', 'same_as' => [], 'read' => false];
        $pages = Page::query()->where('website_asset_id', $site->id)
            ->where(fn ($q) => $q->whereIn('path', ['', '/'])->orWhere('category', 'iletisim')->orWhere('path', 'like', '%iletisim%')->orWhere('path', 'like', '%contact%'))
            ->orderByRaw("case when path in ('', '/') then 0 else 1 end")->limit(4)->get(['path', 'title', 'content_text']);
        $out['text'] = $pages->map(fn (Page $p): string => (string) $p->content_text)->implode(' ');
        $out['read'] = $pages->isNotEmpty();
        $html = $this->homeHtml($site);
        if ($html !== null) {
            $out = array_merge($out, array_filter(self::fromHtml($html), fn (array $v): bool => $v !== []), ['read' => true]);
            $out['phones'] = array_values(array_unique([...$out['phones'], ...self::phones($out['text'])]));
        } else {
            $out['phones'] = self::phones($out['text']);
        }

        return $out;
    }

    private function homeHtml(DigitalAsset $site): ?string
    {
        $url = rtrim((string) ($site->primary_url ?: 'https://'.$site->domain), '/');
        if ($url === 'https://') {
            return null;
        }
        $www = str_contains($url, '://www.') ? str_replace('://www.', '://', $url) : str_replace('://', '://www.', $url);
        $profile = WebsitePageProfile::query()->where('website_asset_id', $site->id)->whereIn('preferred_url', [$url, $url.'/', $www, $www.'/'])->first();
        if ($profile === null) {
            return null;
        }

        return app(SeoStoredHtmlReader::class)->html($site, $profile);
    }

    /**
     * Business facts the page states in its structured data and tel: links.
     *
     * @return array{names: list<string>, phones: list<string>, same_as: list<string>, addresses: list<string>}
     */
    public static function fromHtml(string $html): array
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $out = ['names' => [], 'phones' => [], 'same_as' => [], 'addresses' => []];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode(trim((string) $node->textContent), true);
            if (is_array($decoded)) {
                self::walk($decoded, $out);
            }
        }
        foreach ($xpath->query('//a[starts-with(translate(@href, "TEL", "tel"), "tel:")]') ?: [] as $node) {
            array_push($out['phones'], ...self::phones((string) $node->getAttribute('href')));
        }

        return array_map(fn (array $v): array => array_values(array_unique($v)), $out);
    }

    /**
     * Phone numbers in a text, as the last 10 digits (444 lines: their 7 digits).
     *
     * @return list<string>
     */
    public static function phones(string $text): array
    {
        $out = [];
        preg_match_all('/(?:\+?90[\s.\-]*)?\(?0?\s*[2-5]\d{2}\)?[\s.\-]*\d{3}[\s.\-]*\d{2}[\s.\-]*\d{2}|444[\s.\-]*\d[\s.\-]*\d{3}/u', $text, $matches);
        foreach ($matches[0] as $match) {
            $digits = (string) preg_replace('/\D+/', '', $match);
            $out[] = str_starts_with($digits, '444') && strlen($digits) === 7 ? $digits : substr($digits, -10);
        }

        return array_values(array_unique(array_filter($out, fn (string $d): bool => strlen($d) === 10 || strlen($d) === 7)));
    }

    /**
     * @param  array<mixed>  $node
     * @param  array{names: list<string>, phones: list<string>, same_as: list<string>, addresses: list<string>}  $out
     */
    private static function walk(array $node, array &$out, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        $types = array_map('strval', (array) ($node['@type'] ?? []));
        $business = array_filter($types, fn (string $t): bool => in_array($t, ['Organization', 'LocalBusiness', 'Dentist', 'MedicalClinic', 'MedicalBusiness', 'Physician', 'Hospital', 'ProfessionalService', 'Store', 'Corporation'], true)) !== [];
        if ($business) {
            if (is_string($node['name'] ?? null) && trim($node['name']) !== '') {
                $out['names'][] = trim($node['name']);
            }
            foreach ((array) ($node['telephone'] ?? []) as $phone) {
                array_push($out['phones'], ...self::phones((string) (is_scalar($phone) ? $phone : '')));
            }
            $address = $node['address'] ?? null;
            if (is_array($address)) {
                $out['addresses'][] = implode(' ', array_filter(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $address)));
            } elseif (is_string($address)) {
                $out['addresses'][] = $address;
            }
        }
        foreach ((array) ($node['sameAs'] ?? []) as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $out['same_as'][] = $url;
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value) && $key !== 'sameAs') {
                self::walk($value, $out, $depth + 1);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, state: string, detail: string}
     */
    private function nameRow(Brand $brand, array $profiles, array $facts): array
    {
        $brandName = self::fold((string) $brand->name);
        $core = explode(' ', $brandName)[0] ?? '';
        $off = array_values(array_filter(array_map(fn (array $p): string => GbpDesk::shortName((string) $p['title']), $profiles), fn (string $t): bool => $core !== '' && ! str_contains(self::fold($t), $core)));
        $siteOff = array_values(array_filter((array) ($facts['names'] ?? []), fn (string $n): bool => $core !== '' && ! str_contains(self::fold($n), $core)));
        $issues = [...array_map(fn (string $t): string => 'Profil adı «'.$t.'»', $off), ...array_map(fn (string $n): string => 'Site şemasında «'.$n.'»', $siteOff)];

        return ['key' => 'name', 'label' => 'İşletme adı', 'state' => $issues === [] ? ($profiles === [] && ($facts['names'] ?? []) === [] ? 'unknown' : 'ok') : 'warn',
            'detail' => $issues === [] ? ($profiles === [] && ($facts['names'] ?? []) === [] ? 'Karşılaştıracak İşletme Profili ya da site şeması yok.' : 'Her yerde «'.$brand->name.'» olarak geçiyor.') : implode(' · ', $issues).': marka adıyla aynı olmalı.'];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, state: string, detail: string}|null
     */
    private function phoneRow(array $profiles, array $facts): ?array
    {
        $gbp = [];
        foreach ($profiles as $profile) {
            foreach (self::phones((string) $profile['phone']) as $phone) {
                $gbp[$phone] = (string) $profile['phone'];
            }
        }
        if ($gbp === []) {
            return $profiles === [] ? null : ['key' => 'phone', 'label' => 'Telefon', 'state' => 'warn', 'detail' => 'İşletme Profili\'nde telefon yok.'];
        }
        if (! $facts['read'] || $facts['phones'] === []) {
            return ['key' => 'phone', 'label' => 'Telefon', 'state' => 'unknown', 'detail' => 'Sitede telefon bulunamadı (ana sayfa ve iletişim sayfası okunamadı ya da numara yok).'];
        }
        $missing = array_values(array_diff_key($gbp, array_flip($facts['phones'])));

        return ['key' => 'phone', 'label' => 'Telefon', 'state' => $missing === [] ? 'ok' : 'warn',
            'detail' => $missing === [] ? 'Profildeki numara sitede de var.' : 'Profildeki '.implode(', ', $missing).' sitede geçmiyor; sitede: '.implode(', ', array_slice($facts['phones'], 0, 3)).'.'];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, state: string, detail: string}|null
     */
    private function addressRow(array $profiles, array $facts): ?array
    {
        $profiles = array_values(array_filter($profiles, fn (array $p): bool => (string) $p['address_text'] !== ''));
        if ($profiles === []) {
            return null;
        }
        $haystack = self::fold($facts['text'].' '.implode(' ', (array) ($facts['addresses'] ?? [])));
        if (! $facts['read'] || trim($haystack) === '') {
            return ['key' => 'address', 'label' => 'Adres', 'state' => 'unknown', 'detail' => 'Sitenin ana sayfası ya da iletişim sayfası okunmadı.'];
        }
        $missing = [];
        foreach ($profiles as $profile) {
            $words = array_values(array_filter(explode(' ', self::fold(implode(' ', (array) ($profile['address']['addressLines'] ?? []))) ?: ''),
                fn (string $w): bool => mb_strlen($w) >= 4 && ! in_array($w, self::ADDRESS_NOISE, true) && ! ctype_digit($w)));
            $found = count(array_filter($words, fn (string $w): bool => str_contains($haystack, $w)));
            if ($words === [] || $found * 2 < count($words)) {
                $missing[] = GbpDesk::shortName((string) $profile['title']).': '.$profile['address_text'];
            }
        }

        return ['key' => 'address', 'label' => 'Adres', 'state' => $missing === [] ? 'ok' : 'warn',
            'detail' => $missing === [] ? 'Profillerdeki adresler sitede yazıyor.' : 'Sitede bulunamayan adres: '.implode(' · ', $missing).'. İletişim sayfasında profildekiyle aynı yazılmalı.'];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array{key: string, label: string, state: string, detail: string}|null
     */
    private function websiteRow(array $profiles, string $siteHost): ?array
    {
        if ($profiles === []) {
            return null;
        }
        $off = [];
        foreach ($profiles as $profile) {
            $host = self::host((string) $profile['website']);
            if ($host === '' || ($siteHost !== '' && $host !== $siteHost)) {
                $off[] = GbpDesk::shortName((string) $profile['title']).($host === '' ? ' (bağlantı yok)' : ' ('.$host.')');
            }
        }

        return ['key' => 'website', 'label' => 'Web sitesi bağlantısı', 'state' => $off === [] ? 'ok' : 'warn',
            'detail' => $off === [] ? 'Tüm profiller markanın sitesine bağlanıyor.' : implode(', ', $off).': İşletme profilleri › Profil sekmesinden site bağlantısı düzeltilebilir.'];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $known
     * @param  list<string>  $missing
     * @return array{key: string, label: string, state: string, detail: string}|null
     */
    private function sameAsRow(array $facts, array $known, array $missing): ?array
    {
        if ($known === [] && $facts['same_as'] === []) {
            return null;
        }
        if (! $facts['read']) {
            return ['key' => 'same_as', 'label' => 'Profil bağlantıları (sameAs)', 'state' => 'unknown', 'detail' => 'Sitenin ana sayfası okunmadı.'];
        }

        return ['key' => 'same_as', 'label' => 'Profil bağlantıları (sameAs)', 'state' => $missing === [] ? 'ok' : 'warn',
            'detail' => $missing === [] ? 'Site şeması '.count($facts['same_as']).' profil bağlantısı veriyor.'
                : count($missing).' profil site şemasında yok. SEO eklentisinin "Sosyal profiller / Diğer profiller" alanına eklenince Google ve yapay zekâ bu hesapları aynı işletme sayar.'];
    }

    /** @return array{key: string, label: string, state: string, detail: string} */
    private function expertRow(int $brandId): array
    {
        $author = BrandExpert::authorOf($brandId);

        return match (true) {
            $author === null => ['key' => 'expert', 'label' => 'Uzman yazar', 'state' => 'warn', 'detail' => 'Yazılar bir uzman adına gitmiyor; Ayarlar › Uzmanlar\'dan ekle.'],
            ! filled($author->wp_author) => ['key' => 'expert', 'label' => 'Uzman yazar', 'state' => 'warn', 'detail' => $author->name.' için WordPress kullanıcısı girilmedi.'],
            ! filled($author->profile_url) => ['key' => 'expert', 'label' => 'Uzman yazar', 'state' => 'warn', 'detail' => $author->name.' yazar; sitedeki profil sayfası girilmedi.'],
            default => ['key' => 'expert', 'label' => 'Uzman yazar', 'state' => 'ok', 'detail' => $author->name.' yazar, profil sayfası var.'],
        };
    }

    private static function instagramUrl(DigitalAsset $asset): string
    {
        $url = (string) $asset->primary_url;
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        $handle = ltrim(trim((string) ($asset->name ?? '')), '@');

        return preg_match('/^[A-Za-z0-9._]{2,30}$/', $handle) === 1 ? 'https://www.instagram.com/'.$handle.'/' : '';
    }

    public static function host(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = (string) parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    private static function urlKey(string $url): string
    {
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return self::host($url).strtolower($path).(str_contains($url, 'cid=') ? '?'.parse_url($url, PHP_URL_QUERY) : '');
    }

    private static function fold(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii(str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], $text)))));
    }
}
