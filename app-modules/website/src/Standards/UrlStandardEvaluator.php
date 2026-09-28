<?php

namespace MoxDop\Website\Standards;

use App\Services\SeoTasks\SeoText;

/**
 * Faz 6: evaluates the "url_*" standards of standards.json over one website's joined URL records (see
 * UrlAuditService). Pure; no I/O. Each result is pass / fail / review / unknown / not_applicable with a Turkish
 * finding, the standard's solution and evidence. Missing data is never a failure: a check that needs data the
 * site does not have is not_applicable (source absent) or unknown (page not read).
 */
final class UrlStandardEvaluator
{
    private const array MEDICAL_BUSINESS = ['Dentist', 'MedicalClinic', 'Physician', 'MedicalBusiness', 'MedicalOrganization', 'Hospital'];

    private const array MEDICAL_PAGE = ['MedicalProcedure', 'MedicalTherapy', 'MedicalWebPage', 'MedicalCondition', 'TherapeuticProcedure', 'SurgicalProcedure'];

    private const array ARTICLE = ['Article', 'BlogPosting', 'NewsArticle', 'MedicalWebPage', 'MedicalScholarlyArticle'];

    /** @var array<string, array<string, mixed>> */
    private array $standards = [];

    /** @var array<string, array<string, mixed>> */
    private array $pages = [];

    /** @var array<string, mixed> */
    private array $site = [];

    /**
     * @param  array<string, array<string, mixed>>  $standards  enabled url_* standards keyed by id
     * @param  array<string, array<string, mixed>>  $pages  URL records keyed by url key
     * @param  array<string, mixed>  $site  site context
     * @return array{pages: array<string, array<string, array<string, mixed>>>, site: array<string, array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function evaluate(array $standards, array $pages, array $site): array
    {
        $this->standards = array_filter($standards, fn (array $s): bool => str_starts_with((string) $s['method'], 'url_'));
        $this->pages = $pages;
        $this->site = $site;

        $groups = $this->doorwayGroups();
        $membership = [];
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $membership[$group['kind']][$member] = $group;
            }
        }

        $out = [];
        foreach ($pages as $key => $page) {
            foreach ($this->standards as $id => $standard) {
                if (($standard['applicability'] ?? '') === 'site') {
                    continue;
                }
                $out[$key][$id] = $this->page(substr((string) $standard['method'], 4), $standard, $page, $membership);
            }
        }
        $siteChecks = [];
        foreach ($this->standards as $id => $standard) {
            if (($standard['applicability'] ?? '') === 'site') {
                $siteChecks[$id] = $this->siteCheck(substr((string) $standard['method'], 4), $standard);
            }
        }

        return ['pages' => $out, 'site' => $siteChecks, 'groups' => $groups];
    }

    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $page
     * @param  array<string, array<string, array<string, mixed>>>  $membership
     * @return array<string, mixed>
     */
    private function page(string $method, array $standard, array $page, array $membership): array
    {
        $signals = is_array($page['signals'] ?? null) ? $page['signals'] : null;
        $isPost = $page['kind'] === 'post';
        $content = in_array($page['kind'], ['post', 'service', 'page'], true);
        $failState = ($standard['classification'] ?? 'verified') === 'verified' ? 'fail' : 'review';

        switch ($method) {
            case 'doorway_group':
                $group = $membership['slug'][$page['key']] ?? null;
                if ($group === null) {
                    return $this->na('Sayfa, yalnız konum/ek ile tekrarlanan bir sayfa grubunda değil.');
                }
                $others = count($group['members']) - 1;
                if ($group['keeper'] === $page['key']) {
                    return $this->pass('Bu sayfa "'.$group['head'].'" grubunun korunacak adresi; diğer '.$others.' sayfa buraya birleştirilmeli.', ['group' => $group['key'], 'keeper' => true]);
                }

                return $this->result($failState, $standard, 'Bu sayfa "'.$group['head'].'" konusunu yalnız konum/ek değiştirerek tekrarlayan '.count($group['members']).' sayfalık grubun parçası (kapı sayfa riski).',
                    'Özgün içeriğini '.$this->pathOf($group['keeper']).' sayfasına taşıyıp bu adresi 301 ile oraya yönlendirin.', ['group' => $group['key'], 'keeper' => $group['keeper']]);
            case 'near_duplicate_title':
                $group = $membership['title'][$page['key']] ?? null;
                if ($group === null) {
                    if (! filled($page['title'] ?? null) && ! filled($page['h1'] ?? null)) {
                        return $this->unknown('Sayfanın başlığı / H1’i okunmadı.');
                    }

                    return $this->pass('Başlık ve H1 diğer sayfalardan ayırt edilebiliyor.');
                }
                $others = array_values(array_filter($group['members'], fn (string $m): bool => $m !== $page['key']));

                return $this->result($failState, $standard, 'Başlığı '.count($others).' sayfayla neredeyse aynı: '.implode(', ', array_map(fn (string $m): string => $this->pathOf($m), array_slice($others, 0, 5))).'.',
                    null, ['group' => $group['key'], 'keeper' => $group['keeper']]);
            case 'cannibalization':
                $cannibal = $page['cannibal'] ?? null;
                if (! ($this->site['cannibalization_known'] ?? false)) {
                    return $this->na('Hizmet Beyni yamyamlaşma taraması bu site için çalışmadı.');
                }
                if (! is_array($cannibal)) {
                    return $this->pass('Bu sayfa başka bir sayfamızla aynı sorgu kümesinde yarışmıyor.');
                }
                if ($cannibal['keeper'] === $page['key']) {
                    return $this->pass('"'.$cannibal['subject'].'" kümesinde öndeki (tutulacak) sayfa bu.', ['subject' => $cannibal['subject'], 'keeper' => true]);
                }

                return $this->result('fail', $standard, '"'.$cannibal['subject'].'" aramalarında '.$this->pathOf($cannibal['keeper']).' ile gösterimleri bölüşüyor (bu sayfanın payı %'.(int) round(((float) ($cannibal['share'] ?? 0)) * 100).').',
                    'Bu sayfanın "'.$cannibal['subject'].'" içeriğini '.$this->pathOf($cannibal['keeper']).' sayfasına taşıyın, iç bağlantıları oraya çevirin; ayrı bir amacı yoksa 301 verin.', ['subject' => $cannibal['subject'], 'keeper' => $cannibal['keeper']]);
            case 'eeat_author':
                if (! ($this->site['ymyl'] ?? false)) {
                    return $this->na('Marka YMYL (sağlık, hukuk, finans…) sektöründe değil.');
                }
                if (! $isPost && $page['kind'] !== 'service') {
                    return $this->na('Yazı veya hizmet sayfası değil.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }

                return $signals['author'] ? $this->pass('Sayfada yazar / uzman bilgisi var.') : $this->result($failState, $standard, 'Sayfada içeriği yazan veya veren uzmanın adı / unvanı görünmüyor.');
            case 'eeat_medical_review':
                if (! ($this->site['health'] ?? false)) {
                    return $this->na('Marka sağlık sektöründe değil.');
                }
                if (! $isPost) {
                    return $this->na('Bilgilendirici yazı değil.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }

                return $signals['medical_review'] ? $this->pass('Yazıda tıbbi inceleme notu var.') : $this->result($failState, $standard, 'Yazıda bir hekimin içeriği incelediğine dair not yok.');
            case 'eeat_updated_date':
                if (! ($this->site['ymyl'] ?? false)) {
                    return $this->na('Marka YMYL sektöründe değil.');
                }
                if (! $isPost) {
                    return $this->na('Yazı değil.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }

                return $signals['visible_date'] ? $this->pass('Yazıda görünür tarih var.') : $this->result($failState, $standard, 'Yazıda ziyaretçinin görebileceği bir yayın / güncelleme tarihi yok.');
            case 'medical_procedure_schema':
                if (! ($this->site['health'] ?? false)) {
                    return $this->na('Marka sağlık sektöründe değil.');
                }
                if ($page['kind'] !== 'service') {
                    return $this->na('Tedavi (hizmet) sayfası değil.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }

                return array_intersect($signals['jsonld_types'], self::MEDICAL_PAGE) !== []
                    ? $this->pass('Sayfada tıbbi işlem verisi var.')
                    : $this->result($failState, $standard, 'Tedavi sayfasında MedicalProcedure / MedicalWebPage verisi yok (mevcut: '.($this->typeList($signals)).').');
            case 'faq_schema':
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }
                if (! $signals['faq_content']) {
                    return $this->na('Sayfada soru-cevap bölümü yok.');
                }

                return in_array('FAQPage', $signals['jsonld_types'], true)
                    ? $this->pass('SSS bölümü FAQPage verisiyle işaretli.')
                    : $this->result($failState, $standard, 'Sayfada '.$signals['question_count'].' soru var ama FAQPage verisi yok.');
            case 'article_schema':
                if (! $isPost) {
                    return $this->na('Blog yazısı değil.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }
                $hasType = array_intersect($signals['jsonld_types'], self::ARTICLE) !== [];
                if ($hasType && $signals['date_modified']) {
                    return $this->pass('Yazıda Article/BlogPosting ve dateModified var.');
                }

                return $this->result($failState, $standard, $hasType ? 'Yazı verisinde dateModified yok.' : 'Yazıda Article / BlogPosting verisi yok.');
            case 'breadcrumb_schema':
                $depth = count(array_filter(explode('/', trim((string) $page['path'], '/'))));
                if ($page['kind'] === 'home' || (! $isPost && $depth < 2)) {
                    return $this->na('Ana sayfa veya kök düzeyde sayfa.');
                }
                if ($signals === null) {
                    return $this->unknown('Sayfanın saklı HTML’i okunmadı.');
                }

                return in_array('BreadcrumbList', $signals['jsonld_types'], true)
                    ? $this->pass('Sayfa yolu verisi var.')
                    : $this->result($failState, $standard, 'Alt sayfada BreadcrumbList verisi yok.');
            case 'hreflang_consistency':
                return $this->hreflang($standard, $page, $signals, $failState);
            case 'orphan_page':
                if (! ($this->site['link_graph'] ?? false)) {
                    return $this->na('İç bağlantı haritası henüz toplanmadı.');
                }
                if ($page['kind'] === 'home' || $page['indexable'] !== true) {
                    return $this->na('Ana sayfa veya dizine açık olmayan sayfa.');
                }

                return (int) $page['inlinks'] > 0
                    ? $this->pass((int) $page['inlinks'].' sayfadan iç bağlantı alıyor.')
                    : $this->result($failState, $standard, 'Taranan sayfaların hiçbirinden bu sayfaya bağlantı yok.');
            case 'service_inlinks':
                if (! ($this->site['link_graph'] ?? false)) {
                    return $this->na('İç bağlantı haritası henüz toplanmadı.');
                }
                if (! ($page['is_priority_service'] ?? false) || $page['indexable'] !== true) {
                    return $this->na('Öncelikli hizmet sayfası değil.');
                }
                $min = (int) ($this->site['service_min_inlinks'] ?? 3);

                return (int) $page['inlinks'] >= $min
                    ? $this->pass((int) $page['inlinks'].' sayfadan iç bağlantı alıyor.')
                    : $this->result($failState, $standard, 'Öncelikli hizmet sayfasına yalnız '.(int) $page['inlinks'].' sayfadan bağlantı var (en az '.$min.' önerilir).');
            case 'content_decay':
                if (! $isPost) {
                    return $this->na('Yazı değil.');
                }
                if ($page['clicks_prev'] === null || $page['modified_at'] === null) {
                    return $this->na($page['modified_at'] === null ? 'Yazının güncelleme tarihi bilinmiyor (WordPress bağlı değil).' : 'Search Console verisi yok.');
                }
                $months = (int) ($this->site['decay_months'] ?? 12);
                $old = strtotime((string) $page['modified_at']) < strtotime('-'.$months.' months', (int) ($this->site['now'] ?? time()));
                $falling = $page['clicks_prev'] >= (int) ($this->site['decay_min_prev_clicks'] ?? 10)
                    && $page['clicks'] < (float) ($this->site['decay_ratio'] ?? 0.7) * $page['clicks_prev'];
                if ($old && $falling) {
                    return $this->result($failState, $standard, $months.' aydan uzun süredir güncellenmemiş; Google tıkları '.$page['clicks_prev'].' → '.$page['clicks'].'.');
                }

                return $this->pass($old ? 'Eski ama tıkları düşmüyor.' : 'Son '.$months.' ay içinde güncellenmiş.');
            case 'thin_content':
                if (in_array($page['kind'], ['home', 'contact', 'utility', 'about'], true) || $page['indexable'] === false) {
                    return $this->na('Ana sayfa, iletişim/yardımcı sayfa veya dizine kapalı sayfa.');
                }
                if ($page['word_count'] === null) {
                    return $this->unknown('Kelime sayısı ölçülmedi.');
                }
                $min = (int) ($this->site['thin_words'] ?? 300);

                return $page['word_count'] < $min
                    ? $this->result($failState, $standard, 'Sayfada yalnız '.$page['word_count'].' kelime var (en az '.$min.' önerilir).')
                    : $this->pass($page['word_count'].' kelime.');
            case 'sitemap_hygiene':
                if (! ($this->site['sitemap_known'] ?? false)) {
                    return $this->na('Sitemap okunmadı.');
                }
                if ($page['in_sitemap'] !== true) {
                    return $this->na('Adres sitemap’te değil.');
                }
                $why = $this->nonIndexableReason($page);
                if ($why === null) {
                    return $page['status_code'] === null ? $this->unknown('Adresin HTTP durumu ölçülmedi.') : $this->pass('Sitemap’teki adres dizine açık.');
                }

                return $this->result($failState, $standard, 'Sitemap’te ama '.$why.'.');
            case 'sitemap_missing':
                if (! ($this->site['sitemap_known'] ?? false)) {
                    return $this->na('Sitemap okunmadı.');
                }
                if ($page['indexable'] !== true || in_array($page['kind'], ['utility'], true)) {
                    return $this->na('Dizine açık bir sayfa değil.');
                }

                return $page['in_sitemap'] === true ? $this->pass('Sitemap’te.') : $this->result($failState, $standard, 'Dizine açık sayfa sitemap’te yok.');
            case 'index_coverage':
                $inspection = $page['inspection'] ?? null;
                if (! is_array($inspection) || ($inspection['verdict'] ?? null) === null) {
                    return $this->na('Bu adres için Search Console URL denetimi yok.');
                }
                if (($inspection['verdict'] ?? null) === 'PASS') {
                    return $this->pass('Google dizininde.');
                }
                $valuable = ($page['is_service'] ?? false) || (int) ($page['impr_90'] ?? 0) > 0 || (int) ($page['inlinks'] ?? 0) >= 3 || $page['kind'] === 'home';
                if ($page['indexable'] === false || ! $valuable) {
                    return $this->na('Dizinde değil ama değerli / dizine açık bir sayfa değil.');
                }

                return $this->result('fail', $standard, 'Google dizininde değil: '.($inspection['coverage_state'] ?? 'neden bilinmiyor').'.');
        }

        return $this->unknown('Bu kontrol için değerlendirici yok.');
    }

    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>|null  $signals
     * @return array<string, mixed>
     */
    private function hreflang(array $standard, array $page, ?array $signals, string $failState): array
    {
        $translations = array_values(array_filter((array) ($page['wp_translations'] ?? []), fn ($value): bool => is_string($value) && str_starts_with($value, 'http')));
        if ($signals === null) {
            return $translations !== [] ? $this->unknown('Çevirisi olan sayfanın saklı HTML’i okunmadı.') : $this->na('Çok dilli sayfa verisi yok.');
        }
        $entries = array_values(array_filter($signals['hreflang'], fn (array $e): bool => is_string($e['url'] ?? null)));
        if ($entries === [] && $translations === []) {
            return $this->na('Sayfa tek dilli (hreflang yok).');
        }
        $problems = [];
        $selfKey = $page['key'];
        $languages = [];
        $selfLanguage = null;
        foreach ($entries as $entry) {
            $language = mb_strtolower((string) $entry['language']);
            $languages[$language] = true;
            if (SeoText::urlKey($entry['url']) === $selfKey) {
                $selfLanguage = $language;
            }
        }
        if ($entries === []) {
            $problems[] = 'Polylang çevirisi var ama sayfada hreflang etiketi yok';
        }
        if ($entries !== [] && count(array_diff(array_keys($languages), ['x-default'])) >= 2 && ! isset($languages['x-default'])) {
            $problems[] = 'x-default yok';
        }
        if ($entries !== [] && $selfLanguage === null) {
            $problems[] = 'sayfa kendi hreflang listesinde yok';
        }
        if ($page['canonical_key'] !== null && $page['canonical_key'] !== $selfKey) {
            $problems[] = 'canonical başka adresi gösteriyor ('.$this->pathOf((string) $page['canonical_key']).')';
        }
        $lang = is_string($signals['lang'] ?? null) ? mb_strtolower(substr((string) $signals['lang'], 0, 2)) : null;
        if ($selfLanguage !== null && $selfLanguage !== 'x-default' && $lang !== null && $lang !== substr($selfLanguage, 0, 2)) {
            $problems[] = '<html lang="'.$signals['lang'].'"> ile hreflang "'.$selfLanguage.'" farklı';
        }
        $wpLanguage = is_string($page['wp_language'] ?? null) ? mb_strtolower(substr((string) $page['wp_language'], 0, 2)) : null;
        if ($selfLanguage !== null && $wpLanguage !== null && $selfLanguage !== 'x-default' && $wpLanguage !== substr($selfLanguage, 0, 2)) {
            $problems[] = 'Polylang dili ('.$page['wp_language'].') ile hreflang "'.$selfLanguage.'" farklı';
        }
        $missingReturn = [];
        foreach ($entries as $entry) {
            $targetKey = SeoText::urlKey($entry['url']);
            if ($targetKey === $selfKey) {
                continue;
            }
            $target = $this->pages[$targetKey] ?? null;
            if (! is_array($target) || ! is_array($target['signals'] ?? null)) {
                continue; // not read: cannot judge the return link
            }
            $back = false;
            foreach ($target['signals']['hreflang'] as $return) {
                if (is_string($return['url'] ?? null) && SeoText::urlKey($return['url']) === $selfKey) {
                    $back = true;
                }
            }
            if (! $back) {
                $missingReturn[] = $this->pathOf($targetKey);
            }
        }
        if ($missingReturn !== []) {
            $problems[] = 'geri bağlantı vermeyen çeviri: '.implode(', ', array_slice($missingReturn, 0, 4));
        }
        $hreflangKeys = array_map(fn (array $e): string => SeoText::urlKey($e['url']), $entries);
        $absent = array_filter($translations, fn (string $url): bool => ! in_array(SeoText::urlKey($url), $hreflangKeys, true));
        if ($entries !== [] && $absent !== []) {
            $problems[] = 'Polylang çevirisi hreflang’de yok: '.implode(', ', array_map(fn (string $u): string => (string) parse_url($u, PHP_URL_PATH), array_slice(array_values($absent), 0, 3)));
        }

        return $problems === []
            ? $this->pass('hreflang karşılıklı, x-default var, canonical ve dil tutarlı.')
            : $this->result($failState, $standard, $this->capitalize(implode('; ', $problems)).'.');
    }

    /**
     * @param  array<string, mixed>  $standard
     * @return array<string, mixed>
     */
    private function siteCheck(string $method, array $standard): array
    {
        $home = $this->pages[$this->site['home_key'] ?? ''] ?? null;
        $homeSignals = is_array($home['signals'] ?? null) ? $home['signals'] : null;
        $failState = ($standard['classification'] ?? 'verified') === 'verified' ? 'fail' : 'review';

        switch ($method) {
            case 'eeat_trust_pages':
                if (! ($this->site['ymyl'] ?? false)) {
                    return $this->na('Marka YMYL sektöründe değil.');
                }
                $found = ['about' => false, 'contact' => false, 'team' => false];
                foreach ($this->pages as $page) {
                    $path = mb_strtolower((string) $page['path']);
                    $found['about'] = $found['about'] || preg_match('#/(hakkimizda|hakkinda|about|kurumsal|biz-kimiz|klinigimiz|hakkimda)(/|$)#', $path) === 1;
                    $found['contact'] = $found['contact'] || preg_match('#/(iletisim|contact|bize-ulasin|ulasim)(/|$)#', $path) === 1;
                    $found['team'] = $found['team'] || preg_match('#/(doktor|doktorlar|doktorlarimiz|hekim|hekimlerimiz|ekibimiz|ekip|kadromuz|kadro|uzmanlarimiz|team|doctors|avukatlarimiz)[a-z-]*(/|$)#', $path) === 1;
                }
                $missing = [];
                if (! $found['about']) {
                    $missing[] = 'hakkımızda';
                }
                if (! $found['contact']) {
                    $missing[] = 'iletişim';
                }
                if (($this->site['health'] ?? false) && ! $found['team']) {
                    $missing[] = 'hekim / uzman kadro';
                }
                if ($this->pages === []) {
                    return $this->unknown('Sayfa envanteri boş.');
                }

                return $missing === [] ? $this->pass('Hakkımızda, iletişim'.(($this->site['health'] ?? false) ? ' ve uzman kadro' : '').' sayfaları var.')
                    : $this->result($failState, $standard, 'Sitede şu sayfa bulunamadı: '.implode(', ', $missing).'.', null, ['missing' => $missing]);
            case 'medical_business_schema':
                if (! ($this->site['health'] ?? false)) {
                    return $this->na('Marka sağlık sektöründe değil.');
                }
                if ($homeSignals === null) {
                    return $this->unknown('Ana sayfanın saklı HTML’i okunmadı.');
                }

                return array_intersect($homeSignals['jsonld_types'], self::MEDICAL_BUSINESS) !== []
                    ? $this->pass('Ana sayfada sağlık işletmesi verisi var.')
                    : $this->result($failState, $standard, 'Ana sayfada Dentist / MedicalClinic / Physician türünde veri yok (mevcut: '.$this->typeList($homeSignals).').');
            case 'organization_nap_schema':
                if ($homeSignals === null) {
                    return $this->unknown('Ana sayfanın saklı HTML’i okunmadı.');
                }
                $best = null;
                foreach ($homeSignals['organizations'] as $organization) {
                    $score = (int) filled($organization['name']) + (int) filled($organization['telephone']) + (int) $organization['has_address'];
                    if ($best === null || $score > $best[0]) {
                        $best = [$score, $organization];
                    }
                }
                if ($best === null) {
                    return $this->result($failState, $standard, 'Ana sayfada Organization / LocalBusiness verisi yok.');
                }
                $missing = array_keys(array_filter(['ad' => ! filled($best[1]['name']), 'telefon' => ! filled($best[1]['telephone']), 'adres' => ! $best[1]['has_address']]));

                return $missing === [] ? $this->pass('İşletme verisinde ad, adres ve telefon var.')
                    : $this->result($failState, $standard, 'İşletme verisinde eksik: '.implode(', ', $missing).'.');
            case 'gbp_nap_consistency':
                $gbp = $this->site['gbp'] ?? null;
                if (! is_array($gbp)) {
                    return $this->na('Markaya bağlı tek bir İşletme Profili verisi yok.');
                }
                if ($homeSignals === null) {
                    return $this->unknown('Ana sayfanın saklı HTML’i okunmadı.');
                }
                $sitePhones = [];
                $siteNames = [];
                $sitePostal = [];
                foreach ($this->pages as $page) {
                    if (in_array($page['kind'], ['home', 'contact'], true) && is_array($page['signals'] ?? null)) {
                        array_push($sitePhones, ...$page['signals']['phones']);
                        foreach ($page['signals']['organizations'] as $organization) {
                            if (filled($organization['name'])) {
                                $siteNames[] = (string) $organization['name'];
                            }
                            if (filled($organization['postal_code'])) {
                                $sitePostal[] = (string) $organization['postal_code'];
                            }
                        }
                    }
                }
                $problems = [];
                $checked = [];
                if ($gbp['phones'] !== [] && $sitePhones !== []) {
                    $checked[] = 'telefon';
                    if (array_intersect($gbp['phones'], $sitePhones) === []) {
                        $problems[] = 'telefon farklı (Profil: '.implode(', ', $gbp['phones']).'; site: '.implode(', ', array_slice(array_unique($sitePhones), 0, 3)).')';
                    }
                }
                if (filled($gbp['title'] ?? null) && $siteNames !== []) {
                    $checked[] = 'ad';
                    $gbpName = SeoText::fold((string) $gbp['title']);
                    $match = false;
                    foreach ($siteNames as $name) {
                        $folded = SeoText::fold($name);
                        $match = $match || str_contains($folded, $gbpName) || str_contains($gbpName, $folded);
                    }
                    if (! $match) {
                        $problems[] = 'işletme adı farklı (Profil: '.$gbp['title'].'; site: '.$siteNames[0].')';
                    }
                }
                if (filled($gbp['postal_code'] ?? null) && $sitePostal !== []) {
                    $checked[] = 'posta kodu';
                    if (! in_array((string) $gbp['postal_code'], $sitePostal, true)) {
                        $problems[] = 'adres posta kodu farklı (Profil: '.$gbp['postal_code'].'; site: '.$sitePostal[0].')';
                    }
                }
                if ($checked === []) {
                    return $this->unknown('Sitede karşılaştırılacak telefon / ad / adres bulunamadı.');
                }

                return $problems === [] ? $this->pass('İşletme Profili ile '.implode(', ', $checked).' aynı.')
                    : $this->result($failState, $standard, $this->capitalize(implode('; ', $problems)).'.');
        }

        return $this->unknown('Bu kontrol için değerlendirici yok.');
    }

    /** @return list<array<string, mixed>> */
    private function doorwayGroups(): array
    {
        $wanted = isset($this->standards['website:url:doorway_group']) || isset($this->standards['website:url:near_duplicate_title']);
        if (! $wanted) {
            return [];
        }
        $eligible = [];
        foreach ($this->pages as $key => $page) {
            if (in_array($page['kind'], ['home', 'post', 'utility', 'contact', 'about'], true) || $page['indexable'] === false
                || ($page['status_code'] !== null && $page['status_code'] !== 200)) {
                continue;
            }
            $eligible[$key] = ['path' => (string) $page['path'], 'title' => $page['title'] ?? null, 'h1' => $page['h1'] ?? null,
                'clicks' => $page['clicks'], 'impressions' => $page['impressions'], 'is_service' => (bool) ($page['is_service'] ?? false),
                'inlinks' => $page['inlinks']];
        }
        $detector = new DoorwayGroupDetector(
            (array) ($this->site['locations'] ?? []),
            (array) ($this->site['modifiers'] ?? []),
            (int) ($this->site['doorway_min_group'] ?? 3),
            (float) ($this->site['title_similarity'] ?? 0.88),
        );

        return array_values(array_filter($detector->groups($eligible), fn (array $group): bool => ($group['kind'] === 'slug' && isset($this->standards['website:url:doorway_group']))
            || ($group['kind'] === 'title' && isset($this->standards['website:url:near_duplicate_title']))));
    }

    /** @param  array<string, mixed>  $page */
    private function nonIndexableReason(array $page): ?string
    {
        $status = $page['status_code'];
        if ($status !== null && $status >= 400) {
            return $status.' veriyor';
        }
        if ($status !== null && $status >= 300) {
            return 'yönlendiriliyor';
        }
        if ($page['noindex'] === true) {
            return '"noindex"';
        }
        if ($page['canonical_key'] !== null && $page['canonical_key'] !== $page['key']) {
            return 'canonical başka adresi gösteriyor ('.$this->pathOf((string) $page['canonical_key']).')';
        }

        return null;
    }

    private function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private function pathOf(string $key): string
    {
        $page = $this->pages[$key] ?? null;
        if (is_array($page)) {
            return (string) $page['path'];
        }
        $path = parse_url(str_contains($key, '://') ? $key : 'https://'.$key, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** @param  array<string, mixed>  $signals */
    private function typeList(array $signals): string
    {
        return $signals['jsonld_types'] === [] ? 'yok' : implode(', ', array_slice($signals['jsonld_types'], 0, 6));
    }

    /**
     * @param  array<string, mixed>  $standard
     * @return array<string, mixed>
     */
    private function result(string $state, array $standard, string $finding, ?string $solution = null, mixed $evidence = null): array
    {
        return ['state' => $state, 'finding' => $finding, 'solution' => $solution ?? (string) $standard['action'], 'evidence' => $evidence];
    }

    /** @return array<string, mixed> */
    private function pass(string $finding, mixed $evidence = null): array
    {
        return ['state' => 'pass', 'finding' => $finding, 'solution' => null, 'evidence' => $evidence];
    }

    /** @return array<string, mixed> */
    private function na(string $finding): array
    {
        return ['state' => 'not_applicable', 'finding' => $finding, 'solution' => null, 'evidence' => null];
    }

    /** @return array<string, mixed> */
    private function unknown(string $finding): array
    {
        return ['state' => 'unknown', 'finding' => $finding, 'solution' => null, 'evidence' => null];
    }
}
