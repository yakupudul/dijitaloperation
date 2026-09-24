<?php

namespace App\Services\Intel;

use App\Models\Prospect;
use App\Models\User;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dış denetim (Faz 8f): a quick, honest outside view of a prospect for the sales conversation — public website
 * checks (free, safe fetcher) and, when a service keyword is given, where the business shows up in Google Maps and
 * who takes the top 3 (DataForSEO maps, one task, agency monthly cap). No account access, no AI, no writes.
 */
final class ProspectAuditService implements DataForSeoTaskHandler
{
    /** check key => [label, advice when failing] */
    public const array CHECKS = [
        'https' => ['Güvenli bağlantı (HTTPS)', 'Site HTTPS\'e yönlenmiyor; tarayıcı "güvenli değil" uyarısı verir.'],
        'title' => ['Sayfa başlığı', 'Ana sayfa başlığı yok ya da çok kısa/uzun (30–65 karakter önerilir).'],
        'description' => ['Meta açıklama', 'Google sonuçlarında görünen açıklama yok.'],
        'h1' => ['Ana başlık (H1)', 'Sayfada tek ve net bir ana başlık yok.'],
        'viewport' => ['Mobil uyum etiketi', 'Mobil görünüm etiketi yok; telefonda sayfa küçük görünür.'],
        'phone' => ['Tıklanabilir telefon', 'Telefonla arama bağlantısı (tel:) yok.'],
        'whatsapp' => ['WhatsApp bağlantısı', 'WhatsApp ile yazma bağlantısı yok.'],
        'form' => ['İletişim formu', 'Ana sayfada form yok.'],
        'analytics' => ['Ölçüm etiketi (GA4 / GTM)', 'Google Analytics / Tag Manager bulunamadı; ziyaretçi ve dönüşüm ölçülmüyor.'],
        'pixel' => ['Meta pikseli', 'Meta (Facebook/Instagram) pikseli yok; reklam yeniden hedefleme ve ölçüm yapılamaz.'],
        'schema' => ['Yapılandırılmış veri', 'İşletme bilgisi için yapılandırılmış veri (schema.org) yok.'],
        'indexable' => ['Google\'a açık', 'Sayfa "noindex" ile Google\'dan gizlenmiş.'],
        'sitemap' => ['Site haritası', 'sitemap.xml bulunamadı.'],
        'weight' => ['Sayfa boyutu', 'Ana sayfa HTML\'i 1 MB\'dan büyük; mobilde yavaş açılır.'],
    ];

    public function __construct(
        private readonly PublicPageReader $reader,
        private readonly DataForSeoTaskQueue $queue,
    ) {}

    public function spentThisMonth(): float
    {
        return (float) DB::table('dataforseo_tasks')->where('purpose', 'prospect_maps')->where('posted_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    public function run(Prospect $prospect, ?string $mapsKeyword = null, ?User $actor = null): int
    {
        $url = trim((string) $prospect->website_url);
        $mapsKeyword = trim((string) $mapsKeyword) ?: null;
        if ($url === '' && $mapsKeyword === null) {
            throw ValidationException::withMessages(['audit' => 'Web sitesi ya da harita arama kelimesi gerekli.']);
        }
        if ($mapsKeyword !== null) {
            if (! $this->queue->available()) {
                throw ValidationException::withMessages(['audit' => 'Harita kontrolü için DataForSEO bağlantısı yok.']);
            }
            $cap = (float) config('moxdop-intel.prospect_audit.monthly_usd', 5);
            if ($this->spentThisMonth() + (float) config('moxdop-intel.grid.cost_per_point_usd', 0.0012) > $cap) {
                throw ValidationException::withMessages(['audit' => sprintf('Dış denetim harita tavanı (%.2f USD/ay) doldu.', $cap)]);
            }
        }
        $website = $url !== '' ? $this->websiteChecks($url) : null;
        $id = (int) DB::table('prospect_audits')->insertGetId([
            'prospect_id' => $prospect->id, 'status' => $mapsKeyword !== null ? 'running' : 'completed', 'website_url' => $url ?: null,
            'maps_keyword' => $mapsKeyword, 'website' => $website !== null ? json_encode($website, JSON_UNESCAPED_UNICODE) : null,
            'score' => $website['score'] ?? null, 'created_by' => $actor?->id, 'completed_at' => $mapsKeyword !== null ? null : now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($mapsKeyword !== null) {
            $this->queue->post(DataForSeoEndpointAllowlist::SERP_GOOGLE_MAPS_TASK_POST, MapGridService::GET_PREFIX, 'prospect_maps', null, [[
                'payload' => ['keyword' => $mapsKeyword, 'location_code' => 2792, 'language_code' => 'tr', 'depth' => 20, 'tag' => 'audit:'.$id],
                'subject_type' => 'prospect_audit', 'subject_id' => $id,
            ]]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public function websiteChecks(string $url): array
    {
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $page = $this->reader->fetch($url);
        $html = (string) ($page['body'] ?? '');
        $finalUrl = (string) ($page['final_url'] ?? $url);
        if (! $page['ok'] || $html === '') {
            return ['reachable' => false, 'error' => $page['error'] ?? ('http_'.($page['status_code'] ?? '?')), 'checks' => [], 'score' => 0, 'final_url' => $finalUrl];
        }
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $title = trim((string) ($xpath->query('//title')?->item(0)?->textContent ?? ''));
        $description = trim((string) ($xpath->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content')?->item(0)?->nodeValue ?? ''));
        $h1 = $xpath->query('//h1')?->length ?? 0;
        $robots = mb_strtolower((string) ($xpath->query('//meta[translate(@name,"ROBOTS","robots")="robots"]/@content')?->item(0)?->nodeValue ?? ''));
        $lower = mb_strtolower($html);
        $origin = (string) parse_url($finalUrl, PHP_URL_SCHEME).'://'.(string) parse_url($finalUrl, PHP_URL_HOST);
        $sitemap = $this->reader->fetch($origin.'/sitemap.xml');
        $sitemapOk = $sitemap['ok'] && str_contains((string) $sitemap['body'], '<loc');
        if (! $sitemapOk) {
            $robotsTxt = $this->reader->fetch($origin.'/robots.txt');
            $sitemapOk = $robotsTxt['ok'] && preg_match('/^\s*sitemap:/mi', (string) $robotsTxt['body']) === 1;
        }
        $phones = [];
        preg_match_all('/href=["\']tel:([^"\']+)/i', $html, $tel);
        foreach ($tel[1] as $number) {
            if (($key = WhatsAppContactLinker::key($number)) !== null) {
                $phones[] = $key;
            }
        }
        $checks = [
            'https' => str_starts_with($finalUrl, 'https://'),
            'title' => mb_strlen($title) >= 20 && mb_strlen($title) <= 70,
            'description' => mb_strlen($description) >= 50,
            'h1' => $h1 === 1,
            'viewport' => str_contains($lower, 'name="viewport"') || str_contains($lower, "name='viewport'"),
            'phone' => $phones !== [],
            'whatsapp' => str_contains($lower, 'wa.me/') || str_contains($lower, 'api.whatsapp.com') || str_contains($lower, 'whatsapp://'),
            'form' => str_contains($lower, '<form'),
            'analytics' => str_contains($lower, 'googletagmanager.com') || str_contains($lower, 'gtag(') || str_contains($lower, 'google-analytics.com'),
            'pixel' => str_contains($lower, 'fbq(') || str_contains($lower, 'connect.facebook.net'),
            'schema' => str_contains($lower, 'application/ld+json'),
            'indexable' => ! str_contains($robots, 'noindex'),
            'sitemap' => $sitemapOk,
            'weight' => strlen($html) <= 1_000_000,
        ];

        return [
            'reachable' => true,
            'final_url' => $finalUrl,
            'host' => BrandSetupMatcher::host($finalUrl),
            'title' => mb_substr($title, 0, 200),
            'phones' => array_values(array_unique($phones)),
            'bytes' => strlen($html),
            'checks' => $checks,
            'score' => (int) round(count(array_filter($checks)) / count($checks) * 100),
        ];
    }

    public function handleResult(object $task, array $result): void
    {
        $audit = DB::table('prospect_audits')->find($task->subject_id);
        if ($audit === null) {
            return;
        }
        $prospect = Prospect::query()->find($audit->prospect_id);
        $website = (array) json_decode((string) $audit->website, true);
        $identity = [
            'place_id' => null, 'cid' => null,
            'hosts' => array_values(array_filter([preg_replace('/^www\./', '', (string) ($website['host'] ?? BrandSetupMatcher::host((string) $prospect?->website_url)))])),
            'phones' => array_values(array_filter(array_merge((array) ($website['phones'] ?? []), [WhatsAppContactLinker::key((string) $prospect?->contact_phone)]))),
        ];
        $top = [];
        $ours = null;
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'maps_search') {
                continue;
            }
            $row = [
                'rank' => (int) ($item['rank_group'] ?? count($top) + 1),
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 200),
                'rating' => is_numeric(data_get($item, 'rating.value')) ? (float) data_get($item, 'rating.value') : null,
                'votes' => is_numeric(data_get($item, 'rating.votes_count')) ? (int) data_get($item, 'rating.votes_count') : null,
                'domain' => $item['domain'] ?? null,
                'phone' => $item['phone'] ?? null,
            ];
            $matched = BrandGbpIdentity::matches($row, $identity) || ($prospect !== null && mb_strtolower($row['title']) === mb_strtolower((string) $prospect->company_name));
            if ($matched && $ours === null) {
                $ours = $row;
            }
            $top[] = $row;
        }
        DB::table('prospect_audits')->where('id', $audit->id)->update([
            'maps' => json_encode(['found' => $ours !== null, 'ours' => $ours, 'top3' => array_slice($top, 0, 3), 'results' => count($top)], JSON_UNESCAPED_UNICODE),
            'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function handleFailure(object $task, string $error): void
    {
        DB::table('prospect_audits')->where('id', $task->subject_id)->update(['maps' => json_encode(['error' => mb_substr($error, 0, 300)]), 'status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    }
}
