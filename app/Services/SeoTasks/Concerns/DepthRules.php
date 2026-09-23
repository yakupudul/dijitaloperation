<?php

namespace App\Services\SeoTasks\Concerns;

use App\Enums\SeoTaskType;
use App\Services\SeoTasks\SeoTaskConfig;
use App\Services\SeoTasks\SeoText;
use App\Support\IntelligenceProjection\Website\WebsitePageFamilyClassifier;

/**
 * Faz 2 — web depth rules over data MoxDOP already collects: URL inspection and sitemaps (indexing),
 * page traffic (decay, pruning), the internal link graph, lab speed, and GEO/AEO signals. Every rule
 * stays silent when its data is missing (missing ≠ zero) and groups findings into one task per site
 * where the operator makes one decision.
 */
trait DepthRules
{
    /**
     * Pages that matter: service pages, the homepage and the pages with the most search clicks.
     *
     * @param  array<int, ?string>  $assignments
     * @return array<string, string> url key => why it matters
     */
    private function importantPages(array $input, array $pages, array $assignments): array
    {
        $important = [];
        $home = $input['site']['home_key'] ?? '';
        if ($home !== '' && isset($pages[$home])) {
            $important[$home] = 'ana sayfa';
        }
        foreach ($assignments as $key) {
            if ($key !== null) {
                $important[$key] = 'hizmet sayfası';
            }
        }
        $traffic = $input['traffic']['pages'] ?? [];
        uasort($traffic, static fn (array $a, array $b): int => $b['clicks_cur'] <=> $a['clicks_cur']);
        foreach (array_slice($traffic, 0, SeoTaskConfig::int('indexing.top_traffic_pages', 10), true) as $key => $row) {
            if ($row['clicks_cur'] > 0) {
                $important[$key] ??= 'trafik alan sayfa';
            }
        }

        return $important;
    }

    /**
     * Important pages whose index status is unknown or older than the refresh window, service pages
     * first. The runner sends them to Search Console URL inspection.
     *
     * @param  array<int, ?string>  $assignments
     * @return list<string>
     */
    private function inspectionTargets(array $input, array $pages, array $assignments): array
    {
        $staleBefore = now()->subDays(SeoTaskConfig::int('indexing.refresh_days', 14));
        $order = ['hizmet sayfası' => 0, 'ana sayfa' => 1, 'trafik alan sayfa' => 2];
        $important = $this->importantPages($input, $pages, $assignments);
        uasort($important, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        $targets = [];
        foreach ($important as $key => $why) {
            $inspection = $input['inspections'][$key] ?? null;
            if ($inspection !== null && $inspection['inspected_at'] !== null && now()->parse($inspection['inspected_at'])->greaterThan($staleBefore)) {
                continue;
            }
            $url = $pages[$key]['url'] ?? ($input['traffic']['pages'][$key]['url'] ?? null);
            if (is_string($url)) {
                $targets[] = $url;
            }
        }

        return $targets;
    }

    /** @return list<array<string, mixed>> */
    private function indexingTasks(array $input, array $pages, array $assignments): array
    {
        $tasks = [];
        $important = $this->importantPages($input, $pages, $assignments);
        $inspections = $input['inspections'] ?? [];

        $notIndexed = [];
        $canonical = [];
        foreach ($important as $key => $why) {
            $inspection = $inspections[$key] ?? null;
            if ($inspection === null || $inspection['verdict'] === null) {
                continue; // not inspected yet: the weekly inspection queue will cover it
            }
            if ($inspection['verdict'] !== 'PASS') {
                $notIndexed[] = ['url' => $inspection['url'], 'why' => $why, 'coverage_state' => $inspection['coverage_state'], 'inspected_at' => $inspection['inspected_at']];
            }
            $google = $inspection['google_canonical'];
            $user = $inspection['user_canonical'];
            if ($google !== null && $user !== null && SeoText::urlKey($google) !== SeoText::urlKey($user)) {
                $canonical[] = ['url' => $inspection['url'], 'why' => $why, 'your_canonical' => $user, 'google_canonical' => $google];
            }
        }

        if ($notIndexed !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: 'index-important-pages',
                keyParts: array_map(static fn (array $row): string => SeoText::urlKey($row['url']), $notIndexed),
                severity: 'high',
                score: 860,
                title: sprintf('%d önemli sayfa Google dizininde değil', count($notIndexed)),
                reason: 'Search Console URL incelemesi bu sayfaları "dizinde" olarak göstermiyor. Dizinde olmayan sayfa aramadan hiç trafik alamaz.',
                evidence: ['pages' => $notIndexed],
                checklist: [
                    'Search Console → URL denetimi ile sebebi aç (kapsam durumu kanıtta yazıyor).',
                    'noindex, robots engeli, yönlendirme veya canonical sorununu düzelt.',
                    'Düzelttikten sonra "Dizine eklenmesini iste" ile tekrar gönder.',
                ],
                targetUrl: $notIndexed[0]['url'],
            );
        }
        if ($canonical !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: 'canonical-rejected',
                keyParts: array_map(static fn (array $row): string => SeoText::urlKey($row['url']), $canonical),
                severity: 'medium',
                score: 640,
                title: sprintf('Google %d sayfada senin canonical adresini kabul etmedi', count($canonical)),
                reason: 'Sayfanın gösterdiği canonical ile Google\'ın seçtiği farklı. Sıralama Google\'ın seçtiği adrese gider; iki sayfa birbirinin trafiğini böler.',
                evidence: ['pages' => $canonical],
                checklist: [
                    'İki adres gerçekten aynı içerikse eski adresi 301 ile yönlendir.',
                    'Farklı içeriklerse sayfaları belirginleştir (başlık, H1, içerik) ve iç linkleri doğru adrese ver.',
                    'Sitemap\'te yalnızca asıl adres kalsın.',
                ],
                targetUrl: $canonical[0]['url'],
            );
        }

        $broken = array_values(array_filter($input['sitemaps'] ?? [], static fn (array $s): bool => $s['errors'] > 0));
        if ($broken !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: 'sitemap-errors',
                keyParts: array_map(static fn (array $s): string => $s['path'], $broken),
                severity: 'medium',
                score: 560,
                title: sprintf('%d sitemap dosyasında Search Console hatası var', count($broken)),
                reason: 'Search Console sitemap okurken hata bildiriyor; yeni ve güncellenen sayfalar geç keşfedilir.',
                evidence: ['sitemaps' => $broken],
                checklist: ['Search Console → Site haritaları ekranında hatanın ayrıntısını aç.', 'Hatalı URL\'leri (yönlendirme, 404, noindex) sitemap\'ten çıkar ve dosyayı yeniden gönder.'],
            );
        }

        return $tasks;
    }

    /**
     * Pages with no search impressions for 90 days: one decision per page (keep, update, merge,
     * noindex, redirect), all in one task. Needs ≥ 90 days of Search Console history.
     *
     * @param  array<int, ?string>  $assignments
     * @return list<array<string, mixed>>
     */
    private function pruneTasks(array $input, array $pages, array $assignments): array
    {
        $traffic = $input['traffic'] ?? [];
        if (! ($traffic['available'] ?? false) || ($traffic['history_days'] ?? 0) < 90) {
            return [];
        }
        $trafficPages = $traffic['pages'];
        $servicePages = array_flip(array_filter($assignments));
        $home = $input['site']['home_key'] ?? '';
        $inlinks = $input['links']['inlinks'] ?? [];
        $linksKnown = (bool) ($input['links']['available'] ?? false);
        $thin = SeoTaskConfig::int('prune.thin_words', 300);

        $withTraffic = [];
        foreach ($pages as $key => $page) {
            if (($trafficPages[$key]['impr_90'] ?? 0) > 0) {
                $withTraffic[$key] = $page;
            }
        }

        $rows = [];
        foreach ($pages as $key => $page) {
            if (! $page['observed'] || $key === $home || isset($servicePages[$key]) || $page['noindex']
                || ($page['status_code'] !== null && $page['status_code'] !== 200) || ($trafficPages[$key]['impr_90'] ?? 0) > 0) {
                continue;
            }
            $family = app(WebsitePageFamilyClassifier::class)->classify($page['url'], is_string($page['cms_type']) ? $page['cms_type'] : null);
            if (in_array($family['kind'] ?? '', ['pagination', 'archive', 'parameter', 'media'], true)) {
                continue;
            }
            $label = (string) ($page['title'] ?? $page['h1'] ?? $page['path']);
            $overlap = null;
            foreach ($withTraffic as $otherKey => $other) {
                if (SeoText::tokenOverlap($label, (string) ($other['title'] ?? $other['h1'] ?? '')) >= 0.6) {
                    $overlap = $other;
                    break;
                }
            }
            $inlinkCount = $linksKnown ? count($inlinks[$key] ?? []) : null;
            $words = $page['word_count'];
            [$decision, $why] = match (true) {
                $overlap !== null => ['birleştir', 'Aynı konuyu trafik alan "'.($overlap['title'] ?? $overlap['path']).'" sayfası karşılıyor; içeriği oraya taşı, bu adresi 301 ile yönlendir.'],
                $words !== null && $words < $thin => ['noindex veya kaldır', 'İçerik ince ('.$words.' kelime) ve arama görünürlüğü yok.'],
                $inlinkCount === 0 => ['güncelle ve iç link ver', 'Siteden bu sayfaya hiç link yok; Google önem vermiyor olabilir.'],
                default => ['güncelle', 'İçerik var ama 90 gündür aramada görünmüyor; hedef sorguya göre yeniden yaz.'],
            };
            $rows[] = ['url' => $page['url'], 'title' => $label, 'words' => $words, 'inlinks' => $inlinkCount, 'decision' => $decision, 'why' => $why];
        }

        if (count($rows) < SeoTaskConfig::int('prune.min_pages', 3)) {
            return [];
        }
        $shown = array_slice($rows, 0, 30);

        return [$this->task(
            type: SeoTaskType::Fix,
            ruleId: 'prune-pages',
            keyParts: [(string) intdiv(count($rows), 10)],
            severity: 'medium',
            score: 430,
            title: sprintf('%d sayfa 90 gündür aramada hiç görünmüyor: her biri için karar ver', count($rows)),
            reason: 'Gösterim almayan, ince veya başka sayfayla çakışan sayfalar sitenin genel kalitesini düşürür ve tarama bütçesini harcar. Her sayfa için önerilen karar yazıyor; yeni yayımlanan bir sayfaysa "koru" de.',
            evidence: ['pages' => $shown, 'total' => count($rows), 'history_days' => $traffic['history_days']],
            checklist: [
                'Birleştir: içeriği hedef sayfaya taşı, bu adresi 301 ile yönlendir.',
                'noindex veya kaldır: değersiz sayfayı kaldır (410) ya da noindex yap; sitemap\'ten çıkar.',
                'Güncelle: sayfanın hedeflediği sorguyu belirle, içeriği ve başlığı ona göre yenile, ilgili sayfalardan link ver.',
            ],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function decayTasks(array $input, array $pages): array
    {
        $traffic = $input['traffic'] ?? [];
        if (! ($traffic['available'] ?? false) || ($traffic['history_days'] ?? 0) < SeoTaskConfig::int('decay.window_days', 28) * 2) {
            return [];
        }
        $minPrev = SeoTaskConfig::int('decay.min_previous_clicks', 20);
        $drop = SeoTaskConfig::float('decay.min_drop', 0.4);

        $candidates = [];
        foreach ($traffic['pages'] as $key => $row) {
            if ($row['clicks_prev'] < $minPrev) {
                continue;
            }
            $loss = $row['clicks_prev'] - $row['clicks_cur'];
            if ($loss / $row['clicks_prev'] < $drop) {
                continue;
            }
            $page = $pages[$key] ?? null;
            if ($page !== null && ($page['noindex'] || ($page['status_code'] !== null && $page['status_code'] >= 400))) {
                continue; // an indexing / error problem, not decay
            }
            $candidates[] = ['key' => $key, 'row' => $row, 'loss' => $loss, 'page' => $page];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['loss'] <=> $a['loss']);

        $tasks = [];
        foreach (array_slice($candidates, 0, SeoTaskConfig::int('decay.max_tasks', 3)) as $candidate) {
            $row = $candidate['row'];
            $label = (string) ($candidate['page']['title'] ?? $candidate['page']['h1'] ?? SeoText::urlPath($row['url']));
            $tasks[] = $this->task(
                type: SeoTaskType::Strengthen,
                ruleId: 'content-decay',
                keyParts: [$candidate['key']],
                severity: 'medium',
                score: 300 + min(500, $candidate['loss'] * 5),
                title: 'Yenile: '.mb_substr($label, 0, 120),
                reason: sprintf('Son 28 günde %d tıklama aldı; önceki 28 günde %d idi (%%%d düşüş). Gösterim %d → %d.', $row['clicks_cur'], $row['clicks_prev'], (int) round($candidate['loss'] / $row['clicks_prev'] * 100), $row['impr_prev'], $row['impr_cur']),
                evidence: ['url' => $row['url'], 'clicks_current' => $row['clicks_cur'], 'clicks_previous' => $row['clicks_prev'], 'impressions_current' => $row['impr_cur'], 'impressions_previous' => $row['impr_prev']],
                checklist: [
                    'Bu sayfanın sorgularını Search Console\'da aç: gösterim de düştüyse konu ilgisi azalmış; yalnız tıklama düştüyse sıra veya başlık sorunu.',
                    'Bilgileri, tarihleri ve fiyatları güncelle; rakiplerin cevapladığı eksik soruları ekle.',
                    'Başlık ve açıklamayı yeniden yaz; güncellenme tarihini göster.',
                    'İlgili sayfalardan bu sayfaya link ver.',
                ],
                targetUrl: $row['url'],
                extraClicks: (float) $candidate['loss'],
            );
        }

        return $tasks;
    }

    /**
     * Service pages few pages link to, plus related pages that mention the service but do not link.
     *
     * @param  array<int, ?string>  $assignments
     * @return list<array<string, mixed>>
     */
    private function internalLinkTasks(array $input, array $pages, array $offerings, array $assignments): array
    {
        if (! ($input['links']['available'] ?? false)) {
            return [];
        }
        $inlinks = $input['links']['inlinks'];
        $outlinks = $input['links']['outlinks'];
        $minInlinks = SeoTaskConfig::int('internal_links.min_inlinks', 2);

        $tasks = [];
        foreach ($offerings as $offering) {
            $key = $assignments[$offering['id']] ?? null;
            if ($key === null || ! isset($pages[$key])) {
                continue;
            }
            $sources = $inlinks[$key] ?? [];
            // Names, aliases and matching expressions all identify the service.
            $phrases = array_values(array_unique(array_merge([$offering['name']], $offering['names'] ?? [], $offering['keywords'] ?? [])));
            $related = [];
            foreach ($pages as $otherKey => $other) {
                if ($otherKey === $key || ! $other['observed'] || in_array($key, $outlinks[$otherKey] ?? [], true) || ! isset($outlinks[$otherKey])) {
                    continue; // only pages whose links were actually crawled
                }
                $label = (string) ($other['title'] ?? $other['h1'] ?? '');
                foreach ($phrases as $phrase) {
                    if ($label !== '' && SeoText::containsPhrase($label, (string) $phrase)) {
                        $related[] = ['url' => $other['url'], 'title' => $label];
                        break;
                    }
                }
            }
            $orphan = count($sources) < $minInlinks;
            if (! $orphan && count($related) < 3) {
                continue;
            }
            $tasks[] = $this->task(
                type: SeoTaskType::Strengthen,
                ruleId: 'service-internal-links',
                keyParts: ['offering:'.$offering['id']],
                severity: $offering['is_priority'] ? 'high' : 'medium',
                score: ($offering['is_priority'] ? 450 : 250) + min(100, count($related) * 10),
                title: ($orphan ? 'Hizmet sayfasına neredeyse hiç iç link yok: ' : 'İlgili sayfalar hizmet sayfasına link vermiyor: ').$offering['name'],
                reason: sprintf('Sitede %d sayfa bu hizmet sayfasına link veriyor. Hizmeti anlatan %d sayfa link vermiyor. İç link, Google\'a hangi sayfanın asıl sayfa olduğunu söyler.', count($sources), count($related)),
                evidence: ['url' => $pages[$key]['url'], 'inlinks' => count($sources), 'linking_pages' => array_slice(array_map(fn (string $k): string => $pages[$k]['url'] ?? $k, $sources), 0, 10), 'related_not_linking' => array_slice($related, 0, 10)],
                checklist: [
                    'Listelenen sayfalarda hizmet adının geçtiği cümleye hizmet sayfasının linkini ekle (anlamlı bağlantı metniyle).',
                    'Ana menü veya hizmetler sayfasından da link verildiğinden emin ol.',
                ],
                targetUrl: $pages[$key]['url'],
                offeringId: $offering['id'],
            );
        }
        usort($tasks, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return array_slice($tasks, 0, SeoTaskConfig::int('internal_links.max_tasks', 3));
    }

    /**
     * Lab LCP on the homepage and service pages. Only measured pages are judged.
     *
     * @param  array<int, ?string>  $assignments
     * @return list<array<string, mixed>>
     */
    private function speedTasks(array $input, array $pages, array $assignments): array
    {
        $measurements = $input['performance'] ?? [];
        if ($measurements === []) {
            return [];
        }
        $poor = SeoTaskConfig::int('speed.lcp_poor_ms', 4000);
        $needs = SeoTaskConfig::int('speed.lcp_needs_improvement_ms', 2500);
        $slow = [];
        foreach ($this->importantPages($input, $pages, $assignments) as $key => $why) {
            $m = $measurements[$key] ?? null;
            if ($m === null || $m['lcp_ms'] === null || $m['lcp_ms'] <= $needs) {
                continue;
            }
            $slow[] = ['url' => $m['url'], 'why' => $why, 'lcp_ms' => $m['lcp_ms'], 'strategy' => $m['strategy'], 'observed_at' => $m['observed_at']];
        }
        if ($slow === []) {
            return [];
        }
        $worst = max(array_column($slow, 'lcp_ms'));
        $isPoor = $worst > $poor;

        return [$this->task(
            type: SeoTaskType::Fix,
            ruleId: 'lcp-slow',
            keyParts: [$isPoor ? 'poor' : 'needs'],
            severity: $isPoor ? 'high' : 'medium',
            score: $isPoor ? 620 : 380,
            title: sprintf('%d önemli sayfa yavaş yükleniyor (en yavaş LCP %.1f sn)', count($slow), $worst / 1000),
            reason: sprintf('Ana içerik %s saniyeden geç görünüyor (iyi: 2,5 sn altı). Laboratuvar ölçümüdür; gerçek kullanıcı verisi ayrıca doğrulanmalı.', number_format($worst / 1000, 1, ',', '')),
            evidence: ['pages' => $slow, 'source' => 'PageSpeed (lab)'],
            checklist: [
                'En büyük görseli (genelde üst görsel) WebP/AVIF yap, boyutunu küçült, lazy-load\'u kaldır ve fetchpriority="high" ver.',
                'Engelleyici CSS/JS\'yi azalt; kullanılmayan eklentileri kapat.',
                'Sunucu yanıt süresini kontrol et (önbellek eklentisi / CDN).',
            ],
            targetUrl: $slow[0]['url'],
        )];
    }

    /**
     * GEO/AEO: service schema and a direct answer block on priority service pages, author/expert
     * (E-E-A-T) signals, and site ↔ Business Profile consistency.
     *
     * @param  array<int, ?string>  $assignments
     * @return list<array<string, mixed>>
     */
    private function geoTasks(array $input, array $pages, array $offerings, array $assignments): array
    {
        $tasks = [];
        $serviceTypes = array_map('mb_strtolower', SeoTaskConfig::list('geo.service_schema_types'));
        $noSchema = [];
        $noAnswer = [];
        foreach ($offerings as $offering) {
            $key = $assignments[$offering['id']] ?? null;
            if (! $offering['is_priority'] || $key === null || ! ($pages[$key]['html_read'] ?? false)) {
                continue; // judged only when the stored HTML was read
            }
            $page = $pages[$key];
            $types = array_map('mb_strtolower', $page['structured_types']);
            if (array_intersect($types, $serviceTypes) === []) {
                $noSchema[] = ['offering' => $offering['name'], 'url' => $page['url'], 'found_types' => $page['structured_types']];
            }
            $lead = $page['lead_words'];
            if ($lead !== null && ($lead < 25 || $lead > 120)) {
                $noAnswer[] = ['offering' => $offering['name'], 'url' => $page['url'], 'first_paragraph_words' => $lead];
            }
        }
        if ($noSchema !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::AiVisibility,
                ruleId: 'service-schema',
                keyParts: array_map(static fn (array $r): string => SeoText::urlKey($r['url']), $noSchema),
                severity: 'medium',
                score: 270,
                title: sprintf('%d öncelikli hizmet sayfasına hizmet şeması ekle', count($noSchema)),
                reason: 'Sayfalarda Service (sağlıkta MedicalProcedure) şeması yok. Şema, Google ve AI aramalarına sayfanın hangi hizmeti, kimin, nerede verdiğini makine diliyle söyler.',
                evidence: ['pages' => $noSchema],
                checklist: ['JSON-LD Service ekle: name, description, provider (Organization/LocalBusiness), areaServed (hizmet bölgeleri).', 'Sağlık hizmetlerinde MedicalProcedure kullan; varsa uzman hekimi Person olarak bağla.'],
            );
        }
        if ($noAnswer !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::AiVisibility,
                ruleId: 'answer-block',
                keyParts: array_map(static fn (array $r): string => SeoText::urlKey($r['url']), $noAnswer),
                severity: 'medium',
                score: 260,
                title: sprintf('%d öncelikli hizmet sayfasının başına kısa cevap paragrafı ekle', count($noAnswer)),
                reason: 'H1\'in hemen altındaki ilk paragraf yok, çok kısa ya da çok uzun. AI cevapları ve öne çıkan snippet\'ler genellikle bu 40–60 kelimelik paragrafı alıntılar.',
                evidence: ['pages' => $noAnswer],
                checklist: ['H1\'in hemen altına 40–60 kelimelik bir paragraf yaz: hizmet nedir, kime uygundur, süreç ve yaklaşık süre.', 'Paragraf tek başına okunduğunda soruyu cevaplamalı; pazarlama cümlesiyle başlama.'],
            );
        }

        // E-E-A-T: an article-heavy site without any Person (author/expert) markup.
        $posts = array_filter($pages, static fn (array $p): bool => $p['observed'] && ($p['cms_type'] ?? null) === 'post');
        $read = array_filter($pages, static fn (array $p): bool => (bool) ($p['html_read'] ?? false));
        $hasPerson = (bool) array_filter($read, static fn (array $p): bool => in_array('person', array_map('mb_strtolower', $p['structured_types']), true)
            || (bool) array_intersect(array_map('mb_strtolower', $p['structured_types']), ['physician', 'dentist']));
        if (count($posts) >= SeoTaskConfig::int('geo.author_min_posts', 5) && count($read) >= 5 && ! $hasPerson) {
            $tasks[] = $this->task(
                type: SeoTaskType::AiVisibility,
                ruleId: 'author-eeat',
                keyParts: [],
                severity: 'medium',
                score: 230,
                title: 'Yazar / uzman sayfası ve Person şeması ekle',
                reason: sprintf('Sitede %d yazı var ama okunan %d sayfanın hiçbirinde Person (yazar/uzman) işaretlemesi yok. Sağlık ve para konularında Google ve AI aramaları içeriği yazanın uzmanlığına göre değerlendirir.', count($posts), count($read)),
                evidence: ['posts' => count($posts), 'pages_read' => count($read)],
                checklist: ['Her uzman/hekim için bir profil sayfası aç: unvan, eğitim, deneyim, fotoğraf, sertifikalar.', 'Yazılarda yazar/kontrol eden uzmanı göster ve profil sayfasına bağla.', 'Profil sayfasına Person (sağlıkta Physician) JSON-LD ekle; sameAs ile LinkedIn vb. bağla.'],
            );
        }

        // Entity consistency with the brand's Business Profile.
        $gbp = $input['gbp'] ?? null;
        $home = $pages[$input['site']['home_key'] ?? ''] ?? null;
        if (is_array($gbp)) {
            $issues = [];
            $siteHost = preg_replace('/^www\./', '', (string) parse_url((string) ($input['site']['primary_url'] ?? ''), PHP_URL_HOST));
            $gbpHost = $gbp['website_uri'] !== null ? preg_replace('/^www\./', '', (string) parse_url($gbp['website_uri'], PHP_URL_HOST)) : null;
            if ($gbpHost === null || $gbpHost === '') {
                $issues[] = ['field' => 'web sitesi', 'profile' => '—', 'site' => $siteHost, 'fix' => 'İşletme Profili\'ne web sitesi adresini ekle.'];
            } elseif ($siteHost !== '' && $gbpHost !== $siteHost) {
                $issues[] = ['field' => 'web sitesi', 'profile' => $gbp['website_uri'], 'site' => $siteHost, 'fix' => 'İşletme Profili\'ndeki web sitesini bu siteye çevir.'];
            }
            $sitePhones = $home['tel_numbers'] ?? [];
            if ($gbp['phones'] !== [] && $sitePhones !== [] && array_intersect($gbp['phones'], $sitePhones) === []) {
                $issues[] = ['field' => 'telefon', 'profile' => implode(', ', $gbp['phones']), 'site' => implode(', ', $sitePhones), 'fix' => 'Sitede ve profilde aynı ana telefonu kullan.'];
            }
            $brand = (string) ($input['site']['brand_name'] ?? '');
            if ($gbp['title'] !== null && $brand !== '' && ! $this->sameEntityName($brand, $gbp['title'])) {
                $issues[] = ['field' => 'işletme adı', 'profile' => $gbp['title'], 'site' => $brand, 'fix' => 'Profil adı ile sitedeki kuruluş adı (Organization şeması, logo, alt bilgi) aynı olsun.'];
            }
            if ($issues !== []) {
                $tasks[] = $this->task(
                    type: SeoTaskType::AiVisibility,
                    ruleId: 'entity-consistency',
                    keyParts: array_column($issues, 'field'),
                    severity: 'medium',
                    score: 340,
                    title: 'Site ile İşletme Profili bilgileri uyuşmuyor: '.implode(', ', array_column($issues, 'field')),
                    reason: 'Google ve AI aramaları markayı site, İşletme Profili ve diğer kaynaklardaki ad-adres-telefon tutarlılığıyla doğrular. Uyuşmazlık yerel görünürlüğü düşürür.',
                    evidence: ['issues' => $issues, 'profile_captured_at' => $gbp['captured_at']],
                    checklist: array_values(array_unique(array_column($issues, 'fix'))),
                );
            }
        }

        return $tasks;
    }

    /** Same business name, ignoring generic words ("klinik", "ltd", "merkezi") and word order. */
    private function sameEntityName(string $a, string $b): bool
    {
        $generic = array_flip(SeoTaskConfig::list('geo.generic_name_words'));
        $distinct = static fn (string $text): array => array_values(array_filter(SeoText::tokens($text), static fn (string $t): bool => ! isset($generic[$t]) && mb_strlen($t) > 1));
        $left = $distinct($a);
        $right = $distinct($b);
        if ($left === [] || $right === []) {
            return true; // nothing distinctive to compare
        }
        $compactA = implode('', $left);
        $compactB = implode('', $right);

        return str_contains($compactA, $compactB) || str_contains($compactB, $compactA) || count(array_intersect($left, $right)) / min(count($left), count($right)) >= 0.5;
    }
}
