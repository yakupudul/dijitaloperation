<?php

namespace App\Services\Sales;

use App\Jobs\Sales\CheckProspectSite;
use App\Models\SalesLead;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Bulgu çıkar": a quick look at a prospect's home page (and, through ProspectSiteScan, every page of its sitemap) — the few things a clinic owner understands at once (no
 * https, slow, no phone to tap, no WhatsApp, no measurement, a booking platform instead of their own form…). Each
 * finding carries the sentence for the WhatsApp text (title, consequence, fix). Nothing is written anywhere; the
 * page is fetched once.
 */
final class ProspectSiteCheck
{
    private const int MAX_FINDINGS = 6;

    public static function queue(SalesLead $lead): void
    {
        if ($lead->website_host === null) {
            return;
        }
        $lead->forceFill(['findings_status' => 'queued'])->save();
        try {
            CheckProspectSite::dispatch($lead->id);
        } catch (Throwable $exception) {
            report($exception);
            $lead->forceFill(['findings_status' => 'failed', 'findings' => [['title' => 'Kontrol sıraya alınamadı', 'severity' => 'info']]])->save();
        }
    }

    public function run(SalesLead $lead): void
    {
        $url = (string) ($lead->website ?: 'https://'.$lead->website_host);
        $lead->forceFill(['findings_status' => 'running'])->save();
        try {
            $findings = $this->check($url);
            try {
                $scan = app(ProspectSiteScan::class)->scan($url, $lead->city);
            } catch (Throwable $exception) {
                report($exception);
                $scan = null;
            }
            $lead->forceFill(['findings' => $findings, 'site_scan' => $scan, 'findings_status' => 'ready', 'findings_at' => now()])->save();
            app(SalesLeads::class)->event($lead, 'finding', $findings === []
                ? 'Site kontrolü: belirgin bir sorun bulunmadı.'
                : 'Site kontrolü: '.count($findings).' bulgu. '.implode(' · ', array_map(fn (array $f): string => $f['title'], $findings)),
                meta: ['count' => count($findings)]);
            // The site is read: the next message is written again with the numbers (unless the operator is editing one).
            if ($lead->isOpen() && $lead->draft_status !== 'queued' && (! filled($lead->draft) || $lead->draft === $lead->draft_ai)) {
                SalesReplyWriter::queue($lead);
            }
        } catch (Throwable $exception) {
            // Not a finding to send: a site that blocks robots or did not answer us (geo-block, WAF, timeout) is usually
            // fine for a patient. The card says so; the text falls back to the question without findings.
            $reason = str_starts_with($exception->getMessage(), 'blocked:')
                ? 'sunucu botlara kapalı ('.substr($exception->getMessage(), 8).')'
                : 'yanıt vermedi: '.mb_substr($exception->getMessage(), 0, 120);
            $lead->forceFill(['findings_status' => 'failed', 'findings_at' => now(), 'findings' => []])->save();
            app(SalesLeads::class)->event($lead, 'finding', 'Site okunamadı ('.$reason.'); bulgu çıkarılamadı, telefondan bak.', meta: ['count' => 0, 'failed' => true]);
        }
    }

    /** @return list<array{key: string, title: string, consequence: string, fix: string, severity: string}> */
    public function check(string $url): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $started = microtime(true);
        $response = $this->fetch($url);
        $seconds = round(microtime(true) - $started, 1);
        $html = mb_substr((string) $response->body(), 0, 1_500_000);
        $final = (string) ($response->effectiveUri() ?? $url);
        $lower = mb_strtolower($html);
        $findings = [];
        $add = function (string $key, string $title, string $consequence, string $fix, string $severity = 'look') use (&$findings): void {
            $findings[] = compact('key', 'title', 'consequence', 'fix', 'severity');
        };

        if (in_array($response->status(), [401, 403, 406, 429, 503], true)) {
            throw new \RuntimeException('blocked:'.$response->status());
        }
        if (! $response->successful()) {
            $add('down', 'Site '.$response->status().' hatası veriyor', 'Google ve hastalar siteye giremiyor.', 'Önce siteyi ayağa kaldırırız.', 'critical');

            return $findings;
        }
        if (str_starts_with(strtolower($final), 'http://')) {
            $add('https', 'Site güvenli bağlantı (https) kullanmıyor', 'Tarayıcı "güvenli değil" uyarısı gösteriyor; hastaların bir kısmı o anda çıkıyor.', 'SSL sertifikası kurar, siteyi https\'e taşırız.', 'critical');
        }
        if ($seconds >= 3.0) {
            $add('slow', 'Site yavaş açılıyor ('.number_format($seconds, 1, ',', '.').' sn)', 'Telefondan bakan hastaların bir kısmı sayfa açılmadan geri dönüyor.', 'Hız ayarı ve görsel sıkıştırmayla ilk hafta hızlandırırız.', 'look');
        }
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
        $description = preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/iu', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/iu', $html, $m) ? trim($m[1]) : '';
        if (mb_strlen($title) < 15 || $description === '') {
            $add('seo_basics', 'Google sonucunda '.($description === '' ? 'açıklama' : 'başlık').' görünmüyor', 'Aramada çıkınca hastaya ne yaptığınızı söyleyen satır boş kalıyor; tıklamayı rakip alıyor.', 'Ana sayfa ve hizmet sayfalarının başlık ve açıklamalarını yazarız.', 'look');
        }
        if (! str_contains($lower, 'name="viewport"') && ! str_contains($lower, "name='viewport'")) {
            $add('mobile', 'Site mobil uyumlu görünmüyor', 'Hastaların çoğu telefondan bakıyor; sayfa küçük ve okunmaz geliyor.', 'Mobil görünümü düzeltiriz.', 'critical');
        }
        if (! str_contains($lower, 'href="tel:') && ! str_contains($lower, "href='tel:")) {
            $add('tel', 'Telefon numarası mobilde tek dokunuşla aranmıyor', 'Telefondan bakan hasta numarayı kopyalamak zorunda kalıyor; çoğu aramıyor.', 'Tıklanabilir arama butonu ekleriz.', 'look');
        }
        if (! preg_match('/wa\.me|api\.whatsapp\.com|whatsapp:\/\//iu', $lower)) {
            $add('whatsapp', 'Sitede WhatsApp butonu yok', 'Yazmak isteyen hasta formla uğraşmıyor, çıkıyor.', 'Her sayfaya WhatsApp butonu koyarız.', 'look');
        }
        if (! preg_match('/gtag\(|googletagmanager|fbq\(|clarity\.ms|gtm\.js/iu', $lower)) {
            $add('measure', 'Sitede ölçüm kodu yok', 'Kaç kişinin aradığı, yazdığı ya da form doldurduğu bilinmiyor; reklam verseniz sonucu göremezsiniz.', 'Ölçümü kurar, her ay kaç talep geldiğini raporlarız.', 'look');
        }
        if (preg_match('/doktortakvimi|docplanner|doktorsitesi/iu', $lower)) {
            $add('platform', 'Randevu bir platforma yönleniyor', 'Hasta sizin sitenizden çıkıp platformun sayfasına gidiyor; orada rakip hekimleri de görüyor.', 'Randevu ve WhatsApp akışını sitenizde tutarız.', 'look');
        }
        if (! preg_match('/"@type"\s*:\s*"(Dentist|MedicalClinic|MedicalBusiness|Physician|LocalBusiness|HealthAndBeautyBusiness|MedicalOrganization|Hospital)"/iu', $html)) {
            $add('schema', 'Google\'a klinik bilgisi (işletme işaretlemesi) verilmemiş', 'Google kliniğinizi, şubenizi ve hizmetlerinizi haritada eşleştiremiyor.', 'Klinik işaretlemesini ekleriz.', 'info');
        }
        $h1 = preg_match_all('/<h1\b/iu', $html);
        if ($h1 === 0) {
            $add('h1', 'Ana sayfada ana başlık (H1) yok', 'Google sayfanın neyle ilgili olduğunu başlıktan okuyamıyor.', 'Sayfa yapısını düzeltiriz.', 'info');
        }
        $images = preg_match_all('/<img\b[^>]*>/iu', $html, $imgs);
        if ($images >= 5) {
            $withoutAlt = 0;
            foreach ($imgs[0] as $img) {
                if (! preg_match('/\salt=["\'][^"\']+["\']/iu', $img)) {
                    $withoutAlt++;
                }
            }
            if ($withoutAlt / $images >= 0.4) {
                $add('alt', 'Görsellerin çoğunun açıklaması yok ('.$withoutAlt.'/'.$images.')', 'Google görsel aramasında ve erişilebilirlikte geride kalıyorsunuz.', 'Görsel açıklamalarını yazarız.', 'info');
            }
        }
        $this->checkSitemap($final, $add);

        usort($findings, fn (array $a, array $b): int => self::weight($b['severity']) <=> self::weight($a['severity']));

        return array_slice($findings, 0, self::MAX_FINDINGS);
    }

    private function checkSitemap(string $final, callable $add): void
    {
        $parts = parse_url($final);
        if (! isset($parts['scheme'], $parts['host'])) {
            return;
        }
        try {
            $sitemap = Http::timeout(6)->withHeaders(['User-Agent' => self::userAgent()])->head($parts['scheme'].'://'.$parts['host'].'/sitemap.xml');
            if (! $sitemap->successful()) {
                $add('sitemap', 'Site haritası (sitemap.xml) yok', 'Google yeni sayfalarınızı geç buluyor.', 'Site haritasını kurup Search Console\'a bildiririz.', 'info');
            }
        } catch (Throwable) {
            // A missing sitemap is a weak finding; a timeout is not one.
        }
    }

    private function fetch(string $url): Response
    {
        return Http::timeout(12)->connectTimeout(6)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
            ->withHeaders(['User-Agent' => self::userAgent(), 'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.7'])
            ->get($url);
    }

    private static function userAgent(): string
    {
        return 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 MoxDOP-check';
    }

    private static function weight(string $severity): int
    {
        return match ($severity) {
            'critical' => 3, 'look' => 2, default => 1,
        };
    }
}
