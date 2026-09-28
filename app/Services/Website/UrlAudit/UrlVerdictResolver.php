<?php

namespace App\Services\Website\UrlAudit;

use App\Models\WebsiteUrlVerdict;

/**
 * Faz 5: turns one joined URL record and its standard results into every finding (kural / bulgu / çözüm / aksiyon)
 * and exactly ONE primary verdict, in this order:
 * Birleştir / yönlendir (doorway group or cannibalization, not the kept page) → Dizinden çıkar (junk / test page) →
 * Düzelt (failing standard, open site fix, broken status, valuable page noindex) → Kontrol et (data missing) →
 * Dizinden çıkar (thin, no traffic, no links, not a service page) → Güçlendir (ranking 5–20, decay, thin but valuable,
 * missing FAQ / schema) → Sorun yok — gerek yok (with what was checked). Pure; no I/O.
 */
final class UrlVerdictResolver
{
    private const array BASE = [
        WebsiteUrlVerdict::FIX => 600, WebsiteUrlVerdict::MERGE => 500, WebsiteUrlVerdict::STRENGTHEN => 400,
        WebsiteUrlVerdict::DEINDEX => 300, WebsiteUrlVerdict::CHECK => 200, WebsiteUrlVerdict::OK => 0,
    ];

    private const array SEVERITY = ['high' => 3, 'medium' => 2, 'low' => 1, 'none' => 0];

    /** url_* standard method => [bucket, action tab, fix phase|null, action label]. */
    private const array URL_STANDARD_ACTIONS = [
        'doorway_group' => ['merge', 'fixes', 2, 'Yönlendirmeyi hazırla'],
        'cannibalization' => ['merge', 'fixes', 2, 'Yönlendirmeyi hazırla'],
        'near_duplicate_title' => ['fix', 'fixes', 1, 'Başlık düzeltmesini hazırla'],
        'eeat_author' => ['fix', null, null, null],
        'eeat_medical_review' => ['strengthen', null, null, null],
        'eeat_updated_date' => ['strengthen', null, null, null],
        'medical_procedure_schema' => ['strengthen', 'fixes', 1, 'Yapılandırılmış veri düzeltmesine git'],
        'faq_schema' => ['strengthen', 'fixes', 1, 'Yapılandırılmış veri düzeltmesine git'],
        'article_schema' => ['strengthen', 'fixes', 1, 'Yapılandırılmış veri düzeltmesine git'],
        'breadcrumb_schema' => ['strengthen', 'fixes', 1, 'Yapılandırılmış veri düzeltmesine git'],
        'hreflang_consistency' => ['fix', null, null, null],
        'orphan_page' => ['fix', 'fixes', 2, 'İç bağlantı önerisi hazırla'],
        'service_inlinks' => ['strengthen', 'fixes', 2, 'İç bağlantı önerisi hazırla'],
        'content_decay' => ['strengthen', 'fixes', 3, 'İçerik güncellemesini hazırla'],
        'thin_content' => ['thin', 'fixes', 3, 'İçerik güncellemesini hazırla'],
        'sitemap_hygiene' => ['fix', null, null, null],
        'sitemap_missing' => ['fix', null, null, null],
        'index_coverage' => ['fix', 'search_console', null, 'Search Console’u aç'],
    ];

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $checks  url_* standard results of this URL
     * @param  array<string, mixed>  $context
     * @return array{verdict: string, severity: string, priority: int, reason: string, solution: ?string, findings: list<array<string, mixed>>, group_key: ?string, group: ?array<string, mixed>, checked: list<string>, missing: list<string>}
     */
    public function resolve(array $record, array $checks, array $context): array
    {
        $findings = [];
        $link = fn (?string $tab, ?int $phase = null, array $extra = []): ?string => $tab === null ? null
            : route('operator.website', array_filter(['assetId' => $context['site_id'], 'tab' => $tab, 'fix_phase' => $phase] + $extra, fn ($v): bool => $v !== null));
        $group = null;
        $deindexThin = false;

        // 1. url_* standards (Faz 6).
        foreach ($checks as $id => $check) {
            $standard = $context['standards'][$id] ?? null;
            if ($standard === null) {
                continue;
            }
            $method = substr((string) $standard['method'], 4);
            [$bucket, $tab, $phase, $label] = self::URL_STANDARD_ACTIONS[$method] ?? ['fix', null, null, null];
            if (in_array($check['state'], ['fail', 'review'], true)) {
                if (in_array($method, ['doorway_group', 'near_duplicate_title'], true) && is_array($check['evidence'] ?? null)) {
                    $group = collect($context['groups'])->firstWhere('key', $check['evidence']['group'] ?? null);
                }
            }
            if ($method === 'doorway_group' && ($check['evidence']['keeper'] ?? null) === true) {
                $group = collect($context['groups'])->firstWhere('key', $check['evidence']['group'] ?? null);
                $findings[] = $this->finding('standard', $id, (string) $standard['title'], 'review', 'low', 'strengthen', $check['finding'],
                    'Yönlendirilecek sayfalardaki özgün bilgileri (soru-cevap, bölge bilgisi) bu sayfaya taşıyın; iç bağlantıları buraya çevirin.', null);

                continue;
            }
            $findings[] = $this->finding('standard', $id, (string) $standard['title'], $check['state'], (string) $standard['severity'],
                $bucket, (string) $check['finding'], $check['solution'] ?? null,
                in_array($check['state'], ['fail', 'review'], true) && $label !== null ? ['label' => $label, 'url' => $link($tab, $phase)] : null);
        }

        // 2. Stored standards run (Website › Standartlar).
        foreach ($record['stored_checks'] as $stored) {
            $findings[] = $this->finding('stored_standard', $stored['id'], $stored['title'], $stored['state'], $stored['severity'],
                $stored['state'] === 'fail' ? 'fix' : 'strengthen',
                $stored['criterion'] !== '' ? $stored['criterion'].($stored['observed'] !== null ? ' (gözlenen: '.mb_substr($stored['observed'], 0, 120).')' : '') : 'Standart kontrolü başarısız.',
                $stored['action'], ['label' => 'Standart değerlendirmesini aç', 'url' => $link('standards')]);
        }

        // 3. Open site fixes (existing Düzeltmeler flow).
        foreach ($record['fixes'] as $fix) {
            $queued = $fix['status'] === 'queued';
            $findings[] = $this->finding('fix', 'fix:'.$fix['id'], 'Düzeltme · '.$fix['label'], $queued ? 'review' : 'fail',
                in_array($fix['type'], ['noindex', 'redirect', 'canonical'], true) ? 'high' : 'medium',
                $queued ? 'info' : ($fix['type'] === 'content_update' ? 'strengthen' : 'fix'),
                $fix['reason'].($queued ? ' (onay / uygulama kuyruğunda)' : ($fix['status'] === 'failed' ? ' (son uygulama başarısız)' : '')),
                $this->fixSolution($fix['type']), ['label' => 'Düzeltmeyi hazırla / uygula', 'url' => $link('fixes', $fix['phase'])]);
        }

        // 4. Open SEO tasks.
        foreach ($record['tasks'] as $task) {
            $findings[] = $this->finding('task', 'task:'.$task['id'], 'SEO görevi', 'review', (string) ($task['severity'] ?: 'medium'),
                $task['type'] === 'fix' ? 'fix' : 'strengthen', $task['title'], 'SEO görevindeki adımları uygulayın.',
                ['label' => 'SEO görevini aç', 'url' => $link('seo')]);
        }

        // 5. Direct facts.
        $fixTypes = array_column($record['fixes'], 'type');
        $status = $record['status_code'];
        $utility = preg_match($context['utility_pattern'], (string) $record['path']) === 1;
        if ($status !== null && $status >= 400 && ! in_array('redirect', $fixTypes, true)) {
            $findings[] = $this->finding('signal', 'http_status', 'Sayfa erişilemiyor', 'fail', ($record['inlinks'] ?? 0) > 0 || $record['in_sitemap'] || ($record['clicks'] ?? 0) > 0 ? 'high' : 'medium', 'fix',
                'Adres '.$status.' veriyor'.(($record['inlinks'] ?? 0) > 0 ? ' ve '.$record['inlinks'].' sayfadan bağlantı alıyor' : '').'.',
                'Sayfa taşındıysa en yakın sayfaya 301 verin; bağlantıları ve sitemap’i güncelleyin.', ['label' => 'Yönlendirmeyi hazırla', 'url' => $link('fixes', 2)]);
        }
        $valuable = $record['is_service'] || (int) ($record['clicks'] ?? 0) > 0 || (int) ($record['impr_90'] ?? 0) > 0 || $record['kind'] === 'home';
        if ($record['noindex'] === true && $valuable && ! $utility && ! in_array('noindex', $fixTypes, true)) {
            $findings[] = $this->finding('signal', 'noindex_valuable', 'Değerli sayfa dizine kapalı', 'fail', 'high', 'fix',
                'Sayfa "noindex" ama '.($record['is_service'] ? 'hizmet sayfası' : 'Google’dan trafik/gösterim alıyor').'.',
                'Bilerek kapatılmadıysa noindex’i kaldırın (SEO eklentisi › sayfa ayarı).', ['label' => 'Düzeltmeyi hazırla / uygula', 'url' => $link('fixes', 2)]);
        }
        $junk = preg_match($context['junk_pattern'], (string) $record['path']) === 1
            || ($record['cms_status'] !== null && in_array($record['cms_status'], ['draft', 'private', 'trash', 'pending'], true) && $status === 200);
        if ($junk && $record['indexable'] !== false) {
            $findings[] = $this->finding('signal', 'junk_page', 'Test / taslak sayfa Google’a açık', 'fail', 'medium', 'deindex',
                'Adres test, taslak veya kopya sayfa gibi görünüyor ve dizine açık.',
                'Sayfayı silip 410 verin ya da noindex ekleyin; gerçek bir sayfaysa adını değiştirin.', ['label' => 'Düzeltmeyi hazırla / uygula', 'url' => $link('fixes', 2)]);
        }
        [$low, $high] = array_map('intval', $context['strengthen_positions'] + [5, 20]);
        if ($record['position'] !== null && $record['position'] >= $low && $record['position'] <= $high && (int) ($record['impressions'] ?? 0) >= (int) $context['strengthen_min_impressions']) {
            $findings[] = $this->finding('signal', 'ranking_opportunity', 'Sıralama fırsatı', 'review', 'medium', 'strengthen',
                'Google’da ortalama '.number_format((float) $record['position'], 1, ',', '.').'. sırada, 28 günde '.$record['impressions'].' gösterim'
                .($record['top_queries'] !== [] ? ' ("'.$record['top_queries'][0].'")' : '').'.',
                'İlk 3’e taşımak için arama niyetine yanıt veren bölümler, SSS ve iç bağlantılar ekleyin; başlığı sorguya göre netleştirin.',
                ['label' => 'İçerik güncellemesini hazırla', 'url' => $link('fixes', 3)]);
        }
        $falling = $record['clicks_prev'] !== null && $record['clicks_prev'] >= 20 && (int) $record['clicks'] < 0.7 * $record['clicks_prev'];
        if ($falling && ! in_array($checks['website:url:content_decay']['state'] ?? null, ['fail', 'review'], true)) {
            $findings[] = $this->finding('signal', 'clicks_falling', 'Google tıkları düşüyor', 'review', 'medium', 'strengthen',
                'Google tıkları önceki 28 güne göre '.$record['clicks_prev'].' → '.(int) $record['clicks'].'.',
                'Sorguları Search Console’da kontrol edin; içeriği güncelleyin ve rakip sayfalarla karşılaştırın.', ['label' => 'İçerik güncellemesini hazırla', 'url' => $link('fixes', 3)]);
        }

        // Thin content: strengthen when it has value, deindex when it has none.
        foreach ($findings as &$finding) {
            if ($finding['bucket'] !== 'thin') {
                continue;
            }
            $noValue = (int) ($record['clicks'] ?? 0) === 0 && (int) ($record['impr_90'] ?? $record['impressions'] ?? 0) === 0
                && ($record['inlinks'] === null || (int) $record['inlinks'] === 0) && ! $record['is_service']
                && (int) ($record['sessions'] ?? 0) === 0 && $record['kind'] !== 'home';
            $finding['bucket'] = $noValue ? 'deindex' : 'strengthen';
            if ($noValue) {
                $finding['solution'] = 'Trafiği, bağlantısı ve hizmet karşılığı yok: ilgili bir sayfayla birleştirip 301 verin ya da noindex ile dizinden çıkarın.';
                $finding['action'] = ['label' => 'Düzeltmeyi hazırla / uygula', 'url' => $link('fixes', 2)];
                $deindexThin = true;
            }
        }
        unset($finding);
        // A page that should leave the index does not also need sitemap / internal-link work.
        if ($deindexThin) {
            foreach ($findings as &$finding) {
                if (in_array($finding['id'], ['website:url:sitemap_missing', 'website:url:orphan_page'], true) && $finding['state'] !== 'pass') {
                    $finding['bucket'] = 'info';
                    $finding['solution'] = 'Sayfa dizinden çıkarılacaksa gerekmez.';
                    $finding['action'] = null;
                }
            }
            unset($finding);
        }

        $active = array_values(array_filter($findings, fn (array $f): bool => in_array($f['state'], ['fail', 'review'], true) && $f['bucket'] !== 'info'));
        $byBucket = fn (string $bucket): array => array_values(array_filter($active, fn (array $f): bool => $f['bucket'] === $bucket));
        [$missing, $checked] = $this->coverage($record, $checks, $context);
        $insufficient = $record['status_code'] === null && ! $record['head_observed']
            || ($record['signals'] === null && $record['crawled'] && $record['status_code'] === 200 && ($record['is_service'] || $record['kind'] === 'home' || (int) ($record['clicks'] ?? 0) > 0));

        $junkFinding = array_values(array_filter($active, fn (array $f): bool => $f['id'] === 'junk_page'));
        if ($byBucket('merge') !== []) {
            $verdict = WebsiteUrlVerdict::MERGE;
            $primary = $byBucket('merge');
        } elseif ($junkFinding !== []) {
            $verdict = WebsiteUrlVerdict::DEINDEX;
            $primary = $junkFinding;
        } elseif ($byBucket('fix') !== []) {
            $verdict = WebsiteUrlVerdict::FIX;
            $primary = $byBucket('fix');
        } elseif ($insufficient) {
            $verdict = WebsiteUrlVerdict::CHECK;
            $primary = [];
        } elseif ($byBucket('deindex') !== []) {
            $verdict = WebsiteUrlVerdict::DEINDEX;
            $primary = $byBucket('deindex');
        } elseif ($byBucket('strengthen') !== []) {
            $verdict = WebsiteUrlVerdict::STRENGTHEN;
            $primary = $byBucket('strengthen');
        } else {
            $verdict = WebsiteUrlVerdict::OK;
            $primary = [];
        }
        usort($primary, fn (array $a, array $b): int => self::SEVERITY[$b['severity']] <=> self::SEVERITY[$a['severity']]);
        usort($findings, fn (array $a, array $b): int => [$this->stateRank($b['state']), self::SEVERITY[$b['severity']] ?? 0] <=> [$this->stateRank($a['state']), self::SEVERITY[$a['severity']] ?? 0]);
        $severity = $primary[0]['severity'] ?? ($verdict === WebsiteUrlVerdict::CHECK ? 'low' : 'none');

        $reason = match ($verdict) {
            WebsiteUrlVerdict::MERGE => $primary[0]['finding'],
            WebsiteUrlVerdict::DEINDEX, WebsiteUrlVerdict::STRENGTHEN => $primary[0]['finding'].(count($primary) > 1 ? ' (+'.(count($primary) - 1).' öneri)' : ''),
            WebsiteUrlVerdict::FIX => count($primary).' sorun: '.implode('; ', array_map(fn (array $f): string => $f['rule'], array_slice($primary, 0, 3))).(count($primary) > 3 ? ' …' : '').'.',
            WebsiteUrlVerdict::CHECK => 'Karar için veri eksik: '.implode(', ', $missing ?: ['tarama verisi']).'.',
            default => 'Sorun yok — gerek yok. Kontrol edilenler: '.implode(', ', array_slice($checked, 0, 8)).'.',
        };
        $solution = match ($verdict) {
            WebsiteUrlVerdict::CHECK => 'Eksik veriyi toplayın: '.($record['status_code'] === null ? 'site taramasını çalıştırın (Entegrasyonlar › Web sitesi)' : 'sayfa HTML’ini yeniden toplayın').'; ardından Sayfa Karnesi’ni yenileyin.',
            WebsiteUrlVerdict::OK => 'Bir şey yapmanıza gerek yok.',
            default => $primary[0]['solution'] ?? null,
        };

        $traffic = (int) ($record['clicks'] ?? 0) * 10 + (int) ($record['impressions'] ?? 0) + (int) ($record['sessions'] ?? 0) * 5 + (int) ($record['ads_clicks'] ?? 0) * 5;
        $priority = self::BASE[$verdict] + 40 * self::SEVERITY[$severity] + (int) min(150, 25 * log10(1 + $traffic))
            + ($record['is_service'] ? 40 : 0) + ($record['kind'] === 'home' ? 60 : 0);

        return [
            'verdict' => $verdict, 'severity' => $severity, 'priority' => $priority,
            'reason' => mb_substr($reason, 0, 2000), 'solution' => $solution !== null ? mb_substr($solution, 0, 2000) : null,
            'findings' => $findings, 'group_key' => $group['key'] ?? null,
            'group' => $group !== null ? ['key' => $group['key'], 'kind' => $group['kind'], 'head' => $group['head'], 'keeper' => $group['keeper'], 'members' => array_slice($group['members'], 0, 60)] : null,
            'checked' => $checked, 'missing' => $missing,
        ];
    }

    /**
     * What was checked (for "Sorun yok") and which data is missing (for "Kontrol et").
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $context
     * @return array{0: list<string>, 1: list<string>}
     */
    private function coverage(array $record, array $checks, array $context): array
    {
        $missing = [];
        $checked = [];
        if ($record['status_code'] === null) {
            $missing[] = 'HTTP durumu (sayfa taranmadı)';
        } else {
            $checked[] = 'HTTP '.$record['status_code'];
        }
        if (! $record['head_observed']) {
            $missing[] = 'başlık / meta / robots';
        } else {
            $checked[] = $record['noindex'] ? 'noindex' : 'dizine açık';
            $checked[] = $record['canonical_key'] === null || $record['canonical_key'] === $record['key'] ? 'canonical doğru' : 'canonical başka adres';
        }
        if ($record['word_count'] !== null) {
            $checked[] = $record['word_count'].' kelime';
        }
        if ($record['signals'] === null) {
            $missing[] = 'sayfa HTML’i (E-E-A-T ve yapılandırılmış veri kontrolleri)';
        } else {
            $checked[] = 'yapılandırılmış veri ve E-E-A-T';
        }
        if ($record['inlinks'] !== null) {
            $checked[] = $record['inlinks'].' iç bağlantı';
        }
        if ($record['in_sitemap'] !== null) {
            $checked[] = $record['in_sitemap'] ? 'sitemap’te' : 'sitemap dışı';
        }
        if (! $context['gsc_available']) {
            $missing[] = 'Search Console verisi';
        } elseif ($record['clicks'] !== null) {
            $checked[] = 'Google '.$record['clicks'].' tık';
        }
        if (($record['inspection']['verdict'] ?? null) !== null) {
            $checked[] = $record['inspection']['verdict'] === 'PASS' ? 'Google dizininde' : 'dizin durumu';
        }
        $passed = count(array_filter($checks, fn (array $c): bool => $c['state'] === 'pass'));
        if ($passed > 0) {
            $checked[] = $passed.' standart geçti';
        }

        return [$missing, $checked];
    }

    private function fixSolution(string $type): string
    {
        return match ($type) {
            'seo_title' => 'Başlığı 30–60 karakter, hizmet + bölge içerecek şekilde yeniden yazın.',
            'seo_description' => 'Meta açıklamayı 120–155 karakter, sayfanın faydasını anlatacak şekilde yazın.',
            'alt_text' => 'Görsele içeriğini anlatan alt metin ekleyin.',
            'schema' => 'İşletme yapılandırılmış verisini (LocalBusiness) ekleyin.',
            'redirect' => 'Adresi en yakın sayfaya 301 ile yönlendirin.',
            'noindex' => 'Bilerek kapatılmadıysa noindex’i kaldırın.',
            'canonical' => 'Canonical’ı sayfanın kendi adresine düzeltin.',
            'internal_link' => 'Önerilen iç bağlantıyı ekleyin.',
            'content_update' => 'Sayfa metnini genişletin (AI taslağı hazırlanabilir).',
            default => 'Düzeltmeyi inceleyip uygulayın.',
        };
    }

    private function stateRank(string $state): int
    {
        return ['fail' => 4, 'review' => 3, 'unknown' => 2, 'pass' => 1, 'not_applicable' => 0][$state] ?? 0;
    }

    /**
     * @param  array{label: string, url: ?string}|null  $action
     * @return array<string, mixed>
     */
    private function finding(string $source, string $id, string $rule, string $state, string $severity, string $bucket, string $finding, ?string $solution, ?array $action): array
    {
        return [
            'source' => $source, 'id' => $id, 'rule' => $rule, 'state' => $state,
            'severity' => isset(self::SEVERITY[$severity]) ? $severity : 'medium', 'bucket' => $bucket,
            'finding' => $finding, 'solution' => $solution, 'action' => $action !== null && $action['url'] !== null ? $action : null,
        ];
    }
}
