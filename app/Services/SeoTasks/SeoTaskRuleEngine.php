<?php

namespace App\Services\SeoTasks;

use App\Enums\SeoTaskType;
use App\Models\ServicePageAssignment;

/**
 * Step B — deterministic rules. No LLM, no I/O. Input comes from SeoPlanInputCollector.
 *
 * Returns candidate tasks (not yet persisted), service page assignment decisions and stats.
 * Every candidate carries a stable `task_key` so repeated runs update instead of duplicate.
 */
final class SeoTaskRuleEngine
{
    /** @return array{tasks: list<array<string, mixed>>, assignments: list<array<string, mixed>>, stats: array<string, mixed>} */
    public function evaluate(array $input): array
    {
        $pages = $input['pages'] ?? [];
        $gscRows = $input['gsc']['rows'] ?? [];
        $offerings = $input['offerings'] ?? [];

        $queryIndex = $this->indexQueries($gscRows);
        $offeringQueries = $this->matchQueriesToOfferings($queryIndex, $offerings);
        $assignmentResult = $this->servicePages($input, $pages, $offerings, $offeringQueries, $queryIndex);
        $assignmentsByOffering = $assignmentResult['by_offering'];

        $tasks = [];
        array_push($tasks, ...$assignmentResult['question_tasks']);
        array_push($tasks, ...$this->fixTasks($input, $pages, $assignmentsByOffering, $offerings));
        array_push($tasks, ...$this->strengthenTasks($input, $pages, $offerings, $offeringQueries, $queryIndex, $assignmentsByOffering));
        array_push($tasks, ...$this->createTasks($input, $pages, $offerings, $offeringQueries, $queryIndex, $assignmentsByOffering));
        array_push($tasks, ...$this->aiVisibilityTasks($input, $pages, $offerings, $assignmentsByOffering));

        $tasks = $this->applyQuotas($tasks);

        // Attach the service name (brand offering or AI/rule-inferred topic) to every task's evidence.
        $serviceNames = [];
        $inferred = [];
        foreach ($offerings as $offering) {
            $serviceNames[(string) $offering['id']] = $offering['name'];
            $inferred[(string) $offering['id']] = ! empty($offering['inferred']);
        }
        foreach ($tasks as &$task) {
            $ref = $task['service_ref'] ?? null;
            if ($ref !== null && isset($serviceNames[(string) $ref])) {
                $task['evidence']['service'] = $serviceNames[(string) $ref];
                $task['evidence']['service_inferred'] = $inferred[(string) $ref];
            }
            unset($task['service_ref']);
        }
        unset($task);

        $counts = [];
        foreach ($tasks as $task) {
            $counts[$task['type']] = ($counts[$task['type']] ?? 0) + 1;
        }

        return [
            'tasks' => $tasks,
            'assignments' => $assignmentResult['assignments'],
            'stats' => [
                'gsc_rows' => count($gscRows),
                'gsc_queries' => $input['gsc']['query_count'] ?? 0,
                'pages' => count($pages),
                'findings' => count($input['findings'] ?? []),
                'offerings' => count($offerings),
                'priority_offerings' => count(array_filter($offerings, static fn (array $o): bool => (bool) $o['is_priority'])),
                'matched_queries' => array_sum(array_map('count', $offeringQueries)),
                'counts' => $counts,
            ],
        ];
    }

    // ----------------------------------------------------------------- indexes

    /**
     * Group GSC rows by query: best page, total impressions/clicks, all pages with share.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexQueries(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $key = mb_strtolower((string) $row['query']);
            $entry = $index[$key] ?? [
                'query' => $row['query'],
                'impressions' => 0,
                'clicks' => 0,
                'best_position' => null,
                'best_page' => null,
                'pages' => [],
            ];
            $entry['impressions'] += (int) $row['impressions'];
            $entry['clicks'] += (int) $row['clicks'];
            $position = $row['position'];
            $entry['pages'][$row['url_key']] = [
                'url' => $row['page'],
                'url_key' => $row['url_key'],
                'impressions' => (int) $row['impressions'],
                'clicks' => (int) $row['clicks'],
                'position' => $position,
            ];
            if ($position !== null && ($entry['best_position'] === null || $position < $entry['best_position'])) {
                $entry['best_position'] = (float) $position;
                $entry['best_page'] = $row['url_key'];
            }
            $index[$key] = $entry;
        }

        return $index;
    }

    /**
     * Map every GSC query to offerings by name / alias / matching keyword / portfolio query.
     *
     * @return array<int, list<string>> offering id => list of query keys
     */
    private function matchQueriesToOfferings(array $queryIndex, array $offerings): array
    {
        $result = [];
        foreach ($offerings as $offering) {
            $phrases = array_values(array_unique(array_merge($offering['names'], [$offering['name']], $offering['keywords'])));
            $portfolio = array_flip(array_map('mb_strtolower', $offering['queries']));
            $matched = [];
            foreach ($queryIndex as $key => $entry) {
                if (isset($portfolio[$key])) {
                    $matched[] = $key;

                    continue;
                }
                foreach ($phrases as $phrase) {
                    if (SeoText::containsPhrase($entry['query'], $phrase)) {
                        $matched[] = $key;

                        continue 2;
                    }
                }
                // All name tokens present (e.g. "diş implantı fiyat" for "İmplant Diş")
                if (SeoText::tokenOverlap($entry['query'], $offering['name']) >= 0.99 && count(SeoText::tokens($offering['name'])) > 0) {
                    $matched[] = $key;
                }
            }
            $result[$offering['id']] = array_values(array_unique($matched));
        }

        return $result;
    }

    // ----------------------------------------------------------- service pages

    /**
     * @return array{assignments: list<array<string, mixed>>, by_offering: array<int, ?string>, question_tasks: list<array<string, mixed>>}
     */
    private function servicePages(array $input, array $pages, array $offerings, array $offeringQueries, array $queryIndex): array
    {
        $existing = $input['assignments'] ?? [];
        $autoScore = SeoTaskConfig::float('service_page.auto_assign_score', 0.6);
        $askScore = SeoTaskConfig::float('service_page.ask_score', 0.25);
        $maxCandidates = SeoTaskConfig::int('service_page.max_candidates_in_question', 4);
        $homeKey = $input['site']['home_key'] ?? '';

        $assignments = [];
        $byOffering = [];
        $questions = [];

        foreach ($offerings as $offering) {
            if (! empty($offering['inferred'])) {
                $key = filled($offering['page_url'] ?? null) ? SeoText::urlKey((string) $offering['page_url']) : null;
                $byOffering[$offering['id']] = $key !== null && isset($pages[$key]) ? $key : null;

                continue; // inferred topics are not persisted as assignments and never ask questions
            }
            $current = $existing[$offering['id']] ?? null;
            if ($current !== null && ($current['decision_source'] ?? null) === ServicePageAssignment::SOURCE_OPERATOR) {
                $byOffering[$offering['id']] = $current['status'] === ServicePageAssignment::STATUS_ASSIGNED ? $current['url_key'] : null;

                continue; // operator answer is final
            }

            // Impression share of this offering's queries per page.
            $shareByPage = [];
            $total = 0;
            foreach ($offeringQueries[$offering['id']] ?? [] as $queryKey) {
                foreach ($queryIndex[$queryKey]['pages'] as $urlKey => $page) {
                    $shareByPage[$urlKey] = ($shareByPage[$urlKey] ?? 0) + $page['impressions'];
                    $total += $page['impressions'];
                }
            }

            $scored = [];
            foreach ($pages as $urlKey => $page) {
                if ($urlKey === $homeKey || ($page['status_code'] !== null && $page['status_code'] >= 300) || $page['noindex']) {
                    continue;
                }
                if ($page['cms_status'] !== null && $page['cms_status'] !== 'publish') {
                    continue;
                }
                // Names, aliases and the service's matching keywords all count as identity phrases.
                $phrases = array_values(array_unique(array_merge([$offering['name']], $offering['names'], $offering['keywords'])));
                $slug = SeoText::slugText($page['url']);
                $slugHit = 0.0;
                $titleHit = 0.0;
                foreach ($phrases as $phrase) {
                    $slugHit = max($slugHit, SeoText::identityMatch($slug, $phrase));
                    foreach ([$page['title'], $page['h1']] as $text) {
                        if ($text === null || $text === '') {
                            continue;
                        }
                        $titleHit = max($titleHit, SeoText::containsPhrase($text, $phrase) ? 1.0 : 0.7 * SeoText::identityMatch($text, $phrase));
                    }
                }
                $score = 0.35 * $slugHit + 0.3 * $titleHit;
                if ($total > 0 && isset($shareByPage[$urlKey])) {
                    $score += 0.35 * ($shareByPage[$urlKey] / $total);
                }
                if ($score >= $askScore) {
                    $scored[] = ['url' => $page['url'], 'url_key' => $urlKey, 'score' => round(min(1.0, $score), 4), 'title' => $page['title']];
                }
            }
            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $top = $scored[0] ?? null;

            if ($top !== null && $top['score'] >= $autoScore) {
                $assignments[] = [
                    'brand_offering_id' => $offering['id'],
                    'page_url' => $top['url'],
                    'status' => ServicePageAssignment::STATUS_ASSIGNED,
                    'decision_source' => ServicePageAssignment::SOURCE_AUTO,
                    'score' => $top['score'],
                    'candidates' => array_slice($scored, 0, $maxCandidates),
                ];
                $byOffering[$offering['id']] = $top['url_key'];

                continue;
            }

            if ($top !== null) {
                $candidates = array_slice($scored, 0, $maxCandidates);
                $assignments[] = [
                    'brand_offering_id' => $offering['id'],
                    'page_url' => null,
                    'status' => ServicePageAssignment::STATUS_PENDING_QUESTION,
                    'decision_source' => ServicePageAssignment::SOURCE_AUTO,
                    'score' => $top['score'],
                    'candidates' => $candidates,
                ];
                $byOffering[$offering['id']] = null;
                $questions[] = $this->task(
                    type: SeoTaskType::Question,
                    ruleId: 'service-page-question',
                    keyParts: ['offering:'.$offering['id']],
                    severity: 'medium',
                    score: 500,
                    title: sprintf('"%s" hizmetinin sayfası hangisi?', $offering['name']),
                    reason: sprintf('Sistem %d aday sayfa buldu ama hiçbiri eşik puanı (%.2f) geçmedi. Cevabın kaydedilir, bir daha sorulmaz.', count($candidates), $autoScore),
                    evidence: ['candidates' => $candidates, 'offering' => $offering['name']],
                    checklist: ['Aşağıdaki adaylardan birini seç veya "Sayfası yok" de.'],
                    offeringId: $offering['id'],
                );

                continue;
            }

            $assignments[] = [
                'brand_offering_id' => $offering['id'],
                'page_url' => null,
                'status' => ServicePageAssignment::STATUS_NONE,
                'decision_source' => ServicePageAssignment::SOURCE_AUTO,
                'score' => null,
                'candidates' => [],
            ];
            $byOffering[$offering['id']] = null;
        }

        return ['assignments' => $assignments, 'by_offering' => $byOffering, 'question_tasks' => $questions];
    }

    // -------------------------------------------------------------------- fix

    /** @return list<array<string, mixed>> */
    private function fixTasks(array $input, array $pages, array $assignments = [], array $offerings = []): array
    {
        $tasks = [];
        $accepted = SeoTaskConfig::list('fix.severities_that_become_tasks');
        $thinWords = SeoTaskConfig::int('fix.thin_page_words', 150);
        $chainMin = SeoTaskConfig::int('fix.redirect_chain_min', 2);

        // 1) Existing website findings (critical / high / medium) become tasks 1:1.
        foreach ($input['findings'] ?? [] as $finding) {
            $severity = $finding['severity'];
            if (! in_array($severity, $accepted, true)) {
                continue;
            }
            $mapped = match ($severity) {
                'critical' => 'critical',
                'high', 'warning' => 'high',
                default => 'medium',
            };
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: 'finding:'.($finding['rule_id'] ?: $finding['category'] ?: 'website'),
                keyParts: ['finding:'.$finding['fingerprint']],
                severity: $mapped,
                score: $this->fixScore($mapped),
                title: (string) $finding['title'],
                reason: (string) ($finding['summary'] ?: 'Web sitesi tanısında açık bulgu.'),
                evidence: ['finding_id' => $finding['id'], 'rule_id' => $finding['rule_id'], 'last_seen_at' => $finding['last_seen_at']],
                checklist: $this->findingChecklist((string) $finding['rule_id']),
                targetUrl: is_string($finding['subject_id']) && str_starts_with($finding['subject_id'], 'http') ? $finding['subject_id'] : null,
            );
        }

        // 2) Page-inventory rules, aggregated per rule (one task, URL list as evidence).
        $indexable = array_filter($pages, static fn (array $p): bool => $p['observed']
            && ! $p['noindex']
            && ($p['status_code'] === null || $p['status_code'] === 200)
            && ($p['cms_status'] === null || $p['cms_status'] === 'publish'));
        $observed = array_filter($pages, static fn (array $p): bool => $p['observed']);

        $buckets = [
            'server-error' => ['critical', 'Sunucu hatası veren sayfalar (5xx)', 'Bu sayfalar 5xx döndürüyor; Google bunları dizinden düşürür.', ['Sunucu/log kaydına bak, hatayı gider.', 'Sayfa geri geldiğinde Search Console\'dan yeniden tarama iste.']],
            'title-missing' => ['critical', 'Başlığı olmayan sayfalar', 'Title etiketi yok; arama sonucunda rastgele bir metin görünür.', ['Her sayfaya 50–60 karakterlik, ana sorguyu içeren benzersiz bir title yaz.']],
            'meta-missing' => ['medium', 'Meta açıklaması olmayan sayfalar', 'Meta description boş; tıklama oranı düşer.', ['140–160 karakter, sorguyu ve faydayı içeren bir açıklama yaz.']],
            'h1-missing' => ['medium', 'H1 başlığı olmayan sayfalar', 'Sayfada H1 yok; Google sayfanın konusunu anlamakta zorlanır.', ['Her sayfaya tek bir H1 ekle; ana sorguyu içersin.']],
            'redirect-chain' => ['medium', 'Yönlendirme zinciri olan sayfalar', 'Sayfaya ulaşmak için iki veya daha fazla yönlendirme gerekiyor.', ['İç linkleri doğrudan son URL\'ye çevir.', 'Zinciri tek 301\'e indir.']],
            'canonical-conflict' => ['medium', 'Canonical çelişkisi olan sayfalar', 'Canonical etiketi sayfanın kendi URL\'sini göstermiyor.', ['Canonical\'ı sayfanın kendi (nihai) URL\'sine çevir veya yönlendirmeyi düzelt.']],
            'thin-content' => ['medium', $thinWords.' kelimenin altındaki indekslenebilir sayfalar', 'Çok kısa sayfalar dizinde yer tutar ama sıralanmaz.', ['Sayfayı genişlet (en az 300 kelime) veya noindex/yönlendirme uygula.']],
            'crawl-issue' => ['critical', 'Kırık iç link / tarama hatası olan sayfalar', 'Tarama sırasında kritik hata kaydedildi.', ['Hatalı linkleri düzelt veya kaldır.']],
            'duplicate-h1' => ['medium', 'Birden fazla H1 olan sayfalar', 'Saklı HTML\'de sayfa başına birden fazla H1 var; ana konu sinyali bölünüyor.', ['Sayfada tek H1 bırak (ana sorguyu içeren), diğerlerini H2\'ye çevir.', 'Tema/sayfa oluşturucu logoyu veya menüyü H1 ile basıyorsa şablonu düzelt.']],
            'alt-missing' => ['medium', 'Hizmet sayfalarında alt metni olmayan görseller', 'Hizmet sayfalarındaki görsellerde alt özniteliği yok; görsel arama ve erişilebilirlik kaybı.', ['Her görsele içeriği anlatan, hizmet adını doğal geçiren bir alt metni yaz.', 'Dekoratif görsellerde boş alt="" kullan.']],
        ];
        $hits = array_fill_keys(array_keys($buckets), []);

        foreach ($observed as $urlKey => $page) {
            if ($page['status_code'] !== null && $page['status_code'] >= 500) {
                $hits['server-error'][] = $page['url'];
            }
            if ($page['redirect_count'] !== null && $page['redirect_count'] >= $chainMin) {
                $hits['redirect-chain'][] = $page['url'];
            }
            foreach ($page['crawl_issues'] as $issue) {
                $severity = mb_strtolower((string) ($issue['severity'] ?? ''));
                if (in_array($severity, ['critical', 'error', 'high'], true)) {
                    $hits['crawl-issue'][] = $page['url'].' — '.($issue['code'] ?? $issue['message'] ?? 'issue');
                }
            }
        }
        foreach ($indexable as $urlKey => $page) {
            if ($page['title'] === null || $page['title'] === '') {
                $hits['title-missing'][] = $page['url'];
            }
            if ($page['meta_description'] === null || $page['meta_description'] === '') {
                $hits['meta-missing'][] = $page['url'];
            }
            if (($page['html_read'] ?? false) === true) {
                if ((int) $page['h1_count'] === 0) {
                    $hits['h1-missing'][] = $page['url'];
                } elseif ((int) $page['h1_count'] > 1) {
                    $hits['duplicate-h1'][] = $page['url'].' ('.$page['h1_count'].' H1)';
                }
            } elseif ($page['h1_present'] === false || (($page['h1'] === null || $page['h1'] === '') && $page['h1_present'] !== true && $page['title'] !== null)) {
                $hits['h1-missing'][] = $page['url'];
            }
            if ($page['canonical_hrefs'] !== []) {
                $self = SeoText::urlKey($page['url']);
                $final = $page['final_url'] ? SeoText::urlKey($page['final_url']) : $self;
                $matches = false;
                foreach ($page['canonical_hrefs'] as $href) {
                    $canonical = SeoText::urlKey($href);
                    if ($canonical === $self || $canonical === $final) {
                        $matches = true;
                    }
                }
                if (! $matches) {
                    $hits['canonical-conflict'][] = $page['url'].' → '.$page['canonical_hrefs'][0];
                }
            }
            if ($page['word_count'] !== null && $page['word_count'] < $thinWords) {
                $hits['thin-content'][] = $page['url'].' ('.$page['word_count'].' kelime)';
            }
        }

        // Missing alt on service pages (assigned or inferred), read from stored HTML.
        $servicePageKeys = array_values(array_unique(array_filter($assignments)));
        foreach ($servicePageKeys as $key) {
            $page = $pages[$key] ?? null;
            if ($page !== null && ($page['html_read'] ?? false) && (int) ($page['images_missing_alt'] ?? 0) > 0) {
                $hits['alt-missing'][] = sprintf('%s (%d/%d görsel)', $page['url'], $page['images_missing_alt'], $page['images_total']);
            }
        }

        // Site-wide noindex: nearly every observed page is noindex.
        $noindex = array_filter($observed, static fn (array $p): bool => $p['noindex']);
        if (count($observed) >= 3 && count($noindex) / count($observed) >= 0.9) {
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: 'site-noindex',
                keyParts: [],
                severity: 'critical',
                score: $this->fixScore('critical') + 50,
                title: 'Site genelinde noindex',
                reason: sprintf('Gözlemlenen %d sayfanın %d tanesi noindex. Site dizinden çıkıyor olabilir.', count($observed), count($noindex)),
                evidence: ['sample' => array_slice(array_column($noindex, 'url'), 0, 10)],
                checklist: ['WordPress → Ayarlar → Okuma: "Arama motorlarının görünürlüğünü engelle" kapalı olmalı.', 'SEO eklentisinde site geneli noindex ayarını kontrol et.'],
            );
        }

        foreach ($buckets as $rule => [$severity, $title, $reason, $checklist]) {
            $urls = array_values(array_unique($hits[$rule]));
            if ($urls === []) {
                continue;
            }
            $tasks[] = $this->task(
                type: SeoTaskType::Fix,
                ruleId: $rule,
                keyParts: [],
                severity: $severity,
                score: $this->fixScore($severity) + min(50, count($urls)),
                title: sprintf('%s (%d)', $title, count($urls)),
                reason: $reason,
                evidence: ['count' => count($urls), 'urls' => array_slice($urls, 0, 25), 'truncated' => count($urls) > 25],
                checklist: $checklist,
            );
        }

        return $tasks;
    }

    // -------------------------------------------------------------- strengthen

    /** @return list<array<string, mixed>> */
    private function strengthenTasks(array $input, array $pages, array $offerings, array $offeringQueries, array $queryIndex, array $assignments): array
    {
        $posMin = SeoTaskConfig::float('strengthen.position_min', 5.0);
        $posMax = SeoTaskConfig::float('strengthen.position_max', 20.0);
        $minImpressions = SeoTaskConfig::int('strengthen.min_impressions', 100);
        $noPageAbove = SeoTaskConfig::float('strengthen.no_page_above_position', 5.0);
        $thinService = SeoTaskConfig::int('strengthen.thin_service_page_words', 800);
        $cannibalShare = SeoTaskConfig::float('strengthen.cannibalization_share_min', 0.25);
        $maxPerOffering = SeoTaskConfig::int('strengthen.max_per_priority_service', 2);
        $targetCtr = SeoTaskConfig::targetCtr();
        $ga4 = $input['ga4']['landing'] ?? [];

        $tasks = [];
        foreach ($offerings as $offering) {
            if (! $offering['is_priority']) {
                continue;
            }
            $byPage = [];
            foreach ($offeringQueries[$offering['id']] ?? [] as $queryKey) {
                $entry = $queryIndex[$queryKey];
                if ($entry['impressions'] < $minImpressions || $entry['best_position'] === null) {
                    continue;
                }
                if ($entry['best_position'] < $posMin || $entry['best_position'] > $posMax) {
                    continue;
                }
                $anyAbove = false;
                foreach ($entry['pages'] as $page) {
                    if ($page['position'] !== null && $page['position'] < $noPageAbove) {
                        $anyAbove = true;
                    }
                }
                if ($anyAbove) {
                    continue;
                }
                $gain = max(0.0, $entry['impressions'] * ($targetCtr - SeoTaskConfig::ctrAt($entry['best_position'])));
                $gain = max(0.0, min($gain, $entry['impressions'] * $targetCtr - $entry['clicks']));
                $targetKey = $entry['best_page'];
                $byPage[$targetKey]['queries'][] = [
                    'query' => $entry['query'],
                    'impressions' => $entry['impressions'],
                    'clicks' => $entry['clicks'],
                    'position' => $entry['best_position'],
                    'gain' => round($gain, 1),
                ];
                $byPage[$targetKey]['gain'] = ($byPage[$targetKey]['gain'] ?? 0) + $gain;
                $byPage[$targetKey]['impressions'] = ($byPage[$targetKey]['impressions'] ?? 0) + $entry['impressions'];

                // Cannibalization: another page with meaningful share on the same query.
                $totalImpr = array_sum(array_column($entry['pages'], 'impressions'));
                foreach ($entry['pages'] as $urlKey => $page) {
                    if ($urlKey !== $targetKey && $totalImpr > 0 && $cannibalShare <= $page['impressions'] / $totalImpr) {
                        $byPage[$targetKey]['competing'][$urlKey] = $page['url'];
                    }
                }
            }

            uasort($byPage, static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);
            $count = 0;
            foreach ($byPage as $urlKey => $bucket) {
                if ($count >= $maxPerOffering) {
                    break;
                }
                usort($bucket['queries'], static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);
                $queries = array_slice($bucket['queries'], 0, 8);
                $page = $pages[$urlKey] ?? null;
                $url = $page['url'] ?? ($queryIndex[mb_strtolower($queries[0]['query'])]['pages'][$urlKey]['url'] ?? $urlKey);
                $top = $queries[0]['query'];
                $checklist = [];
                if ($page !== null) {
                    if ($page['title'] === null || ! SeoText::containsPhrase($page['title'], $top)) {
                        $checklist[] = sprintf('Title\'a "%s" ifadesini ekle (şu an: %s).', $top, $page['title'] ?: 'boş');
                    }
                    if ($page['h1'] === null || ! SeoText::containsPhrase($page['h1'], $top)) {
                        $checklist[] = sprintf('H1\'e "%s" ifadesini ekle.', $top);
                    }
                    if ($page['meta_description'] === null || ! SeoText::containsPhrase($page['meta_description'], $top)) {
                        $checklist[] = sprintf('Meta açıklamasında "%s" geçsin.', $top);
                    }
                    if ($page['word_count'] !== null && $page['word_count'] < $thinService) {
                        $checklist[] = sprintf('Sayfayı genişlet: %d kelime → en az %d kelime. Aşağıdaki sorguların her birine bir bölüm/H2 ayır.', $page['word_count'], $thinService);
                    }
                } else {
                    $checklist[] = 'Sayfa envanterde yok; önce WP envanterini yenile, sonra title/H1/meta kontrolü yap.';
                }
                if (count($queries) > 1) {
                    $checklist[] = 'Şu sorguları sayfa içinde H2/SSS olarak kapsa: '.implode(', ', array_map(static fn (array $q): string => '"'.$q['query'].'"', array_slice($queries, 1, 5))).'.';
                }
                $checklist[] = 'Sitedeki en az 3 ilgili sayfadan bu sayfaya, sorguyu içeren bağlantı metniyle iç link ver.';
                if (! empty($bucket['competing'])) {
                    $checklist[] = 'Niyeti ayır: '.implode(', ', array_values($bucket['competing'])).' aynı sorgularda yarışıyor. Birini farklı niyete odakla; otomatik 301 uygulama.';
                }
                $landing = $ga4[$urlKey] ?? null;
                if ($landing !== null && $landing['sessions'] >= 50 && $landing['key_events'] === 0) {
                    $checklist[] = sprintf('Sayfa %d oturum aldı ama dönüşüm kaydı yok: net bir CTA (telefon/form) ekle.', $landing['sessions']);
                }

                $tasks[] = $this->task(
                    type: SeoTaskType::Strengthen,
                    ruleId: 'strengthen-page',
                    keyParts: ['url:'.$urlKey, 'offering:'.$offering['id']],
                    severity: $bucket['gain'] >= 50 ? 'high' : 'medium',
                    score: 100 + min(800, $bucket['gain'] * 10) + 50,
                    title: sprintf('"%s" için sayfayı güçlendir: %s', $offering['name'], SeoText::urlPath($url)),
                    reason: sprintf('%d sorgu 5–20. sırada, hiçbiri ilk 5\'te değil. %s gösterimde tahmini +%d tıklama/90 gün.', count($bucket['queries']), number_format($bucket['impressions']), (int) round($bucket['gain'])),
                    evidence: ['queries' => $queries, 'impressions' => $bucket['impressions'], 'page' => $page ? ['title' => $page['title'], 'h1' => $page['h1'], 'word_count' => $page['word_count']] : null, 'competing' => array_values($bucket['competing'] ?? [])],
                    checklist: $checklist,
                    targetUrl: $url,
                    offeringId: $offering['id'],
                    extraClicks: round($bucket['gain'], 1),
                );
                $count++;
            }
        }

        return $tasks;
    }

    // ------------------------------------------------------------------ create

    /** @return list<array<string, mixed>> */
    private function createTasks(array $input, array $pages, array $offerings, array $offeringQueries, array $queryIndex, array $assignments): array
    {
        $minImpr = SeoTaskConfig::int('create.min_impressions', 30);
        $rankedMax = SeoTaskConfig::int('create.ranked_position_max', 20);
        $minPerSite = SeoTaskConfig::int('create.min_per_site', 4);
        $maxPerSite = SeoTaskConfig::int('create.max_per_site', 6);
        $bucketMax = SeoTaskConfig::int('create.bucket_max_queries', 12);
        $areas = array_map(static fn (string $a): string => SeoText::fold($a), $input['service_areas'] ?? []);
        $ctr5 = SeoTaskConfig::ctrAt(5.0);
        $pageTexts = $this->pageTextIndex($pages);
        $origin = $input['site']['origin'] ?? '';

        $buckets = []; // key => bucket
        $usedQueries = [];

        // 1) GSC queries with demand but no page in the top N → grouped by offering × intent.
        foreach ($offerings as $offering) {
            foreach ($offeringQueries[$offering['id']] ?? [] as $queryKey) {
                $entry = $queryIndex[$queryKey];
                if ($entry['impressions'] < $minImpr) {
                    continue;
                }
                if ($entry['best_position'] !== null && $entry['best_position'] <= $rankedMax) {
                    continue;
                }
                $intent = $this->intent($entry['query'], $areas);
                $bucketKey = $offering['id'].'|'.$intent['type'].'|'.$intent['location'];
                $buckets[$bucketKey] ??= $this->newBucket($offering, $intent, 'gsc');
                $buckets[$bucketKey]['queries'][] = ['query' => $entry['query'], 'impressions' => $entry['impressions'], 'clicks' => $entry['clicks'], 'position' => $entry['best_position'], 'source' => 'gsc'];
                $buckets[$bucketKey]['impressions'] += $entry['impressions'];
                $usedQueries[$queryKey] = true;
            }
        }

        // 2) Library / portfolio queries no page covers (title, H1 or slug) and GSC never saw.
        foreach ($offerings as $offering) {
            foreach ($offering['queries'] as $text) {
                $key = mb_strtolower($text);
                if (isset($usedQueries[$key]) || isset($queryIndex[$key])) {
                    continue;
                }
                if ($this->anyPageCovers($pageTexts, $text)) {
                    continue;
                }
                $intent = $this->intent($text, $areas);
                $bucketKey = $offering['id'].'|'.$intent['type'].'|'.$intent['location'];
                $buckets[$bucketKey] ??= $this->newBucket($offering, $intent, 'library');
                if (count($buckets[$bucketKey]['queries']) >= $bucketMax) {
                    continue;
                }
                $buckets[$bucketKey]['queries'][] = ['query' => $text, 'impressions' => null, 'clicks' => null, 'position' => null, 'source' => 'library'];
                $usedQueries[$key] = true;
            }
        }

        // 3) Priority offering without any service page → service page candidate.
        foreach ($offerings as $offering) {
            if (! $offering['is_priority'] || ($assignments[$offering['id']] ?? null) !== null) {
                continue;
            }
            $bucketKey = $offering['id'].'|service|';
            $buckets[$bucketKey] ??= $this->newBucket($offering, ['type' => 'service', 'location' => ''], 'inventory');
            $buckets[$bucketKey]['missing_service_page'] = true;
            if ($buckets[$bucketKey]['queries'] === []) {
                $buckets[$bucketKey]['queries'] = array_map(
                    static fn (string $q): array => ['query' => $q, 'impressions' => null, 'clicks' => null, 'position' => null, 'source' => 'suggested'],
                    $this->seedQueries($offering, 'service', ''),
                );
            }
        }

        // Score & rank.
        $ranked = [];
        foreach ($buckets as $key => $bucket) {
            $expected = $bucket['impressions'] * $ctr5;
            $score = 80 + min(700, $expected * 10) + ($bucket['offering']['is_priority'] ? 200 : 0) + (($bucket['missing_service_page'] ?? false) ? 150 : 0);
            $bucket['expected_clicks'] = round($expected, 1);
            $bucket['score'] = $score;
            usort($bucket['queries'], static fn (array $a, array $b): int => ($b['impressions'] ?? 0) <=> ($a['impressions'] ?? 0));
            $bucket['queries'] = array_slice($bucket['queries'], 0, $bucketMax);
            $ranked[$key] = $bucket;
        }
        uasort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $selected = array_slice($ranked, 0, $maxPerSite, true);

        // 4) Guarantee the weekly minimum: fall back to guide/FAQ briefs per offering (priority first).
        if (count($selected) < $minPerSite) {
            $ordered = $offerings;
            usort($ordered, static fn (array $a, array $b): int => [$b['is_priority'], $a['priority_rank'] ?? PHP_INT_MAX] <=> [$a['is_priority'], $b['priority_rank'] ?? PHP_INT_MAX]);
            foreach (['guide', 'faq', 'location'] as $fallbackType) {
                foreach ($ordered as $offering) {
                    if (count($selected) >= $minPerSite) {
                        break 2;
                    }
                    if ($fallbackType === 'location' && $areas === []) {
                        continue;
                    }
                    $location = $fallbackType === 'location' ? ($input['service_areas'][0] ?? '') : '';
                    $key = $offering['id'].'|'.$fallbackType.'|'.SeoText::fold($location);
                    if (isset($selected[$key]) || isset($ranked[$key])) {
                        if (isset($ranked[$key]) && ! isset($selected[$key])) {
                            $selected[$key] = $ranked[$key];
                        }

                        continue;
                    }
                    $seed = $this->seedQueries($offering, $fallbackType, $location);
                    if ($this->anyPageCovers($pageTexts, $seed[0])) {
                        continue;
                    }
                    $bucket = $this->newBucket($offering, ['type' => $fallbackType, 'location' => SeoText::fold($location), 'location_label' => $location], 'fallback');
                    $bucket['queries'] = array_map(static fn (string $q): array => ['query' => $q, 'impressions' => null, 'clicks' => null, 'position' => null, 'source' => 'suggested'], $seed);
                    $bucket['expected_clicks'] = null;
                    $bucket['score'] = 60 + ($offering['is_priority'] ? 100 : 0);
                    $selected[$key] = $bucket;
                }
            }
        }

        $tasks = [];
        foreach ($selected as $bucket) {
            $brief = $this->brief($bucket, $origin, $assignments, $pages);
            $queryTexts = array_column($bucket['queries'], 'query');
            $reasonParts = [];
            if ($bucket['impressions'] > 0) {
                $reasonParts[] = sprintf('%s gösterim alan %d sorgu için sitede ilk %d\'de hiçbir sayfa yok (tahmini +%d tıklama/90 gün 5. sırada).', number_format($bucket['impressions']), count(array_filter($bucket['queries'], static fn (array $q): bool => $q['source'] === 'gsc')), $rankedMax, (int) round($bucket['expected_clicks'] ?? 0));
            }
            $library = count(array_filter($bucket['queries'], static fn (array $q): bool => $q['source'] === 'library'));
            if ($library > 0) {
                $reasonParts[] = sprintf('%d kütüphane sorgusunu hiçbir sayfa başlık/H1/URL düzeyinde karşılamıyor.', $library);
            }
            if ($bucket['missing_service_page'] ?? false) {
                $reasonParts[] = 'Öncelikli hizmetin sitede tanımlı bir hizmet sayfası yok.';
            }
            if ($bucket['source'] === 'fallback') {
                $reasonParts[] = 'Haftalık asgari içerik kotası için önerildi: bu hizmette veri az, konu boşluğu tahmini.';
            }
            $tasks[] = $this->task(
                type: SeoTaskType::Create,
                ruleId: 'create-'.$bucket['intent']['type'],
                keyParts: ['offering:'.$bucket['offering']['id'], 'loc:'.$bucket['intent']['location']],
                severity: $bucket['offering']['is_priority'] ? 'high' : 'medium',
                score: $bucket['score'],
                title: $brief['page_title'],
                reason: implode(' ', $reasonParts),
                evidence: ['queries' => $bucket['queries'], 'impressions' => $bucket['impressions'], 'source' => $bucket['source']],
                checklist: $brief['checklist'],
                targetUrl: $brief['target_url'],
                offeringId: $bucket['offering']['id'],
                extraClicks: $bucket['expected_clicks'],
                isNewPage: $brief['decision'] === 'new_page',
                brief: $brief,
            );
        }

        return $tasks;
    }

    /** @return array{type: string, location: string, location_label?: string} */
    private function intent(string $query, array $areas): array
    {
        $folded = ' '.SeoText::fold($query).' ';
        foreach ($areas as $index => $area) {
            if ($area !== '' && str_contains($folded, ' '.$area.' ')) {
                return ['type' => 'location', 'location' => $area, 'location_label' => $area];
            }
        }
        if (SeoText::looksLikeQuestion($query)) {
            return ['type' => 'guide', 'location' => ''];
        }

        return ['type' => 'service', 'location' => ''];
    }

    private function newBucket(array $offering, array $intent, string $source): array
    {
        return [
            'offering' => $offering,
            'intent' => $intent + ['location_label' => $intent['location_label'] ?? $intent['location']],
            'queries' => [],
            'impressions' => 0,
            'source' => $source,
        ];
    }

    /** @return list<string> */
    private function seedQueries(array $offering, string $type, string $location): array
    {
        $name = $offering['name'];
        $lower = mb_strtolower($name, 'UTF-8');

        return match ($type) {
            'guide' => [$lower.' nasıl yapılır', $lower.' fiyatları', $lower.' ne kadar sürer', $lower.' öncesi ve sonrası'],
            'faq' => [$lower.' hakkında sık sorulan sorular', $lower.' avantajları', $lower.' riskleri', $lower.' kimler için uygun'],
            'location' => [mb_strtolower($location, 'UTF-8').' '.$lower, $lower.' '.mb_strtolower($location, 'UTF-8'), $lower.' '.mb_strtolower($location, 'UTF-8').' fiyat'],
            default => [$lower, $lower.' hizmeti', $lower.' fiyat'],
        };
    }

    /** @return array<string, mixed> */
    private function brief(array $bucket, string $origin, array $assignments, array $pages): array
    {
        $offering = $bucket['offering'];
        $type = $bucket['intent']['type'];
        $location = $bucket['intent']['location_label'] ?? '';
        $queries = array_column($bucket['queries'], 'query');
        $topQuery = $queries[0] ?? $offering['name'];
        $targetWords = SeoTaskConfig::int('create.target_words.'.$type, 1200);
        $servicePageKey = $assignments[$offering['id']] ?? null;
        $servicePage = $servicePageKey !== null ? ($pages[$servicePageKey]['url'] ?? null) : null;

        $decision = 'new_page';
        $targetUrl = null;
        if ($type === 'faq' && $servicePage !== null) {
            $decision = 'existing_page_section';
            $targetUrl = $servicePage;
        }
        if ($type === 'service' && $servicePage !== null && ! ($bucket['missing_service_page'] ?? false)) {
            $decision = 'existing_page_section';
            $targetUrl = $servicePage;
        }
        if ($targetUrl === null) {
            $slug = match ($type) {
                'guide' => SeoText::slugify($topQuery),
                'faq' => SeoText::slugify($offering['name'].' sss'),
                'location' => SeoText::slugify($location.' '.$offering['name']),
                default => SeoText::slugify($offering['name']),
            };
            $targetUrl = rtrim($origin, '/').'/'.($type === 'guide' ? 'blog/' : '').$slug.'/';
        }

        $pageTitle = match ($type) {
            'guide' => ucfirst($topQuery).': adım adım rehber',
            'faq' => $offering['name'].' hakkında sık sorulan sorular',
            'location' => $location.' '.$offering['name'],
            default => $offering['name'].' — hizmet sayfası',
        };
        if (($bucket['missing_service_page'] ?? false) && $type === 'service') {
            $pageTitle = $offering['name'].' hizmet sayfası oluştur';
        }

        $outline = match ($type) {
            'guide' => [
                $topQuery.' nedir?',
                'Süreç adım adım',
                'Süre, fiyat ve etkileyen faktörler',
                'Kimler için uygun / uygun değil',
                'Sık sorulan sorular',
                'Randevu / teklif CTA',
            ],
            'faq' => array_merge(array_map(static fn (string $q): string => ucfirst($q).'?', array_slice($queries, 0, 6)), ['Uzmanla görüşün (CTA)']),
            'location' => [
                $location.' bölgesinde '.$offering['name'],
                'Neden biz: deneyim, ekip, referans',
                'Süreç ve fiyatlandırma',
                'Ulaşım ve çalışma saatleri',
                'Sık sorulan sorular',
                'Randevu CTA',
            ],
            default => [
                $offering['name'].' nedir, kimler için?',
                'Uygulama süreci',
                'Fiyatlandırma ve seçenekler',
                'Öncesi / sonrası, referanslar',
                'Sık sorulan sorular',
                'Randevu / teklif CTA',
            ],
        };

        $checklist = [
            sprintf('%s: %s', $decision === 'new_page' ? 'Yeni sayfa aç' : 'Mevcut sayfaya bölüm ekle', $targetUrl),
            sprintf('Title/H1: "%s" ana sorgusunu içersin.', $topQuery),
            sprintf('Hedef uzunluk: ~%d kelime; H2 taslağını takip et.', $targetWords),
        ];
        if ($servicePage !== null && $decision === 'new_page') {
            $checklist[] = sprintf('Hizmet sayfasına (%s) ve oradan bu sayfaya iç link ver.', $servicePage);
        }
        $checklist[] = 'Yayınlandıktan sonra Search Console\'dan URL denetimi ile dizine gönder.';

        return [
            'decision' => $decision,
            'page_type' => $type,
            'page_title' => $pageTitle,
            'target_url' => $targetUrl,
            'h2_outline' => $outline,
            'queries' => array_slice($queries, 0, SeoTaskConfig::int('create.bucket_max_queries', 12)),
            'target_words' => $targetWords,
            'internal_links' => array_values(array_filter([$servicePage])),
            'offering' => $offering['name'],
            'location' => $location !== '' ? $location : null,
            'checklist' => $checklist,
            'source' => 'rules',
        ];
    }

    /** @return list<string> folded page texts (title + h1 + slug) */
    private function pageTextIndex(array $pages): array
    {
        $texts = [];
        foreach ($pages as $page) {
            if (! $page['observed'] && $page['title'] === null) {
                continue;
            }
            $texts[] = SeoText::fold(implode(' ', array_filter([$page['title'], $page['h1'], SeoText::slugText($page['url'])])));
        }

        return $texts;
    }

    private function anyPageCovers(array $pageTexts, string $query): bool
    {
        $tokens = SeoText::tokens($query);
        if ($tokens === []) {
            return false;
        }
        foreach ($pageTexts as $text) {
            if (SeoText::tokenOverlap($text, $query) >= 0.99) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------- ai visibility

    /** @return list<array<string, mixed>> */
    private function aiVisibilityTasks(array $input, array $pages, array $offerings, array $assignments): array
    {
        $tasks = [];
        $home = $pages[$input['site']['home_key'] ?? ''] ?? null;

        // 1) Organization / LocalBusiness schema on the homepage.
        if ($home !== null && $home['observed']) {
            $hasOrg = false;
            foreach ($home['structured_types'] as $type) {
                $lower = mb_strtolower($type);
                if (str_contains($lower, 'organization') || str_contains($lower, 'business') || $lower === 'dentist' || $lower === 'medicalclinic' || $lower === 'localbusiness') {
                    $hasOrg = true;
                }
            }
            if ($hasOrg && ($home['html_read'] ?? false) && ($home['same_as'] ?? []) === []) {
                $tasks[] = $this->task(
                    type: SeoTaskType::AiVisibility,
                    ruleId: 'org-same-as',
                    keyParts: [],
                    severity: 'low',
                    score: 240,
                    title: 'Kuruluş şemasına sameAs bağlantılarını ekle',
                    reason: 'Ana sayfada kuruluş şeması var ama sameAs listesi boş. AI arama motorları markayı sosyal profiller ve işletme profiliyle eşleştiremiyor.',
                    evidence: ['found_types' => $home['structured_types'], 'url' => $home['url']],
                    checklist: ['Organization/LocalBusiness JSON-LD içine sameAs dizisi ekle: Instagram, Facebook, LinkedIn, YouTube, Google İşletme Profili URL\'leri.', 'Aynı bağlantıları sitenin alt bilgisinde de göster.'],
                    targetUrl: $home['url'],
                );
            }
            if (! $hasOrg) {
                $tasks[] = $this->task(
                    type: SeoTaskType::AiVisibility,
                    ruleId: 'org-schema',
                    keyParts: [],
                    severity: 'medium',
                    score: 300,
                    title: 'Ana sayfaya Organization/LocalBusiness şeması ekle',
                    reason: 'Ana sayfada kuruluş şeması bulunamadı. AI arama ve Google, markayı sameAs bağlantılarıyla doğrular.',
                    evidence: ['found_types' => $home['structured_types'], 'url' => $home['url']],
                    checklist: ['JSON-LD Organization (veya LocalBusiness alt türü) ekle: name, url, logo, telephone, address.', 'sameAs: Instagram, Facebook, LinkedIn, Google İşletme Profili bağlantıları.'],
                    targetUrl: $home['url'],
                );
            }
        }

        // 2) robots.txt blocks for AI / Google bots.
        $robots = $input['robots'] ?? [];
        if (($robots['available'] ?? false) && is_string($robots['body'])) {
            $blocked = $this->blockedBots($robots['body'], SeoTaskConfig::list('ai_visibility.blocked_bots'));
            if ($blocked !== []) {
                $googleBlocked = (bool) array_filter($blocked, static fn (string $b): bool => str_starts_with(mb_strtolower($b), 'googlebot'));
                $tasks[] = $this->task(
                    type: SeoTaskType::AiVisibility,
                    ruleId: 'robots-bot-block',
                    keyParts: [],
                    severity: $googleBlocked ? 'critical' : 'medium',
                    score: $googleBlocked ? 950 : 320,
                    title: 'robots.txt şu botları engelliyor: '.implode(', ', $blocked),
                    reason: $googleBlocked ? 'Googlebot engellenmiş; site dizinden düşer.' : 'AI arama botları engellenmiş; ChatGPT/Claude aramalarında site görünmez.',
                    evidence: ['blocked' => $blocked, 'observed_at' => $robots['observed_at'] ?? null],
                    checklist: ['robots.txt içindeki ilgili Disallow satırlarını kaldır veya sadece özel dizinlerle sınırla.'],
                );
            }
        }

        // 3) FAQ block on priority service pages.
        $missingFaq = [];
        foreach ($offerings as $offering) {
            if (! $offering['is_priority']) {
                continue;
            }
            $key = $assignments[$offering['id']] ?? null;
            if ($key === null || ! isset($pages[$key])) {
                continue;
            }
            $types = array_map('mb_strtolower', $pages[$key]['structured_types']);
            if (! in_array('faqpage', $types, true)) {
                $missingFaq[] = ['offering' => $offering['name'], 'url' => $pages[$key]['url']];
            }
        }
        if ($missingFaq !== []) {
            $tasks[] = $this->task(
                type: SeoTaskType::AiVisibility,
                ruleId: 'faq-block',
                keyParts: [],
                severity: 'medium',
                score: 280,
                title: sprintf('%d öncelikli hizmet sayfasına soru-cevap bloğu ekle', count($missingFaq)),
                reason: 'Öncelikli hizmet sayfalarında FAQPage şeması yok. Soru-cevap blokları AI cevaplarında alıntılanma şansını artırır.',
                evidence: ['pages' => $missingFaq],
                checklist: ['Her sayfaya 4–6 gerçek müşteri sorusu ve kısa cevap ekle.', 'FAQPage JSON-LD ile işaretle.'],
            );
        }

        return $tasks;
    }

    /**
     * Bots whose effective robots group has "Disallow: /". A specific group wins over "*".
     *
     * @param  list<string>  $bots
     * @return list<string>
     */
    public function blockedBots(string $body, array $bots): array
    {
        $groups = []; // agent (lower) => ['disallow_all' => bool]
        $currentAgents = [];
        $inDirectives = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = mb_strtolower($directive);
            if ($directive === 'user-agent') {
                if ($inDirectives) {
                    $currentAgents = [];
                    $inDirectives = false;
                }
                $agent = mb_strtolower($value);
                $currentAgents[] = $agent;
                $groups[$agent] ??= ['disallow_all' => false];

                continue;
            }
            $inDirectives = true;
            if ($directive === 'disallow' && $value === '/') {
                foreach ($currentAgents as $agent) {
                    $groups[$agent]['disallow_all'] = true;
                }
            }
            if ($directive === 'allow' && $value === '/') {
                foreach ($currentAgents as $agent) {
                    $groups[$agent]['disallow_all'] = false;
                }
            }
        }

        $blocked = [];
        foreach ($bots as $bot) {
            $lower = mb_strtolower($bot);
            $group = $groups[$lower] ?? $groups['*'] ?? null;
            if ($group !== null && $group['disallow_all']) {
                $blocked[] = $bot;
            }
        }

        return array_values(array_unique($blocked));
    }

    // ------------------------------------------------------------------ quotas

    /** @return list<array<string, mixed>> */
    private function applyQuotas(array $tasks): array
    {
        $perType = [
            SeoTaskType::Create->value => SeoTaskConfig::int('quotas.create_per_site', 6),
            SeoTaskType::Strengthen->value => SeoTaskConfig::int('quotas.strengthen_per_site', 6),
            SeoTaskType::Fix->value => SeoTaskConfig::int('quotas.fix_per_site', 6),
            SeoTaskType::AiVisibility->value => SeoTaskConfig::int('quotas.ai_visibility_per_site', 3),
            SeoTaskType::Question->value => 10,
        ];
        $total = SeoTaskConfig::int('quotas.open_tasks_per_site', 15);
        $createMin = SeoTaskConfig::int('create.min_per_site', 4);

        usort($tasks, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        $byType = [];
        foreach ($tasks as $task) {
            $type = $task['type'];
            if (count($byType[$type] ?? []) >= ($perType[$type] ?? PHP_INT_MAX)) {
                continue;
            }
            $byType[$type][] = $task;
        }

        // Protect the weekly content minimum, then fill the rest by score.
        $selected = array_slice($byType[SeoTaskType::Create->value] ?? [], 0, $createMin);
        $selectedKeys = array_flip(array_column($selected, 'task_key'));
        $rest = [];
        foreach ($byType as $list) {
            foreach ($list as $task) {
                if (! isset($selectedKeys[$task['task_key']])) {
                    $rest[] = $task;
                }
            }
        }
        usort($rest, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);
        foreach ($rest as $task) {
            if (count($selected) >= $total) {
                break;
            }
            $selected[] = $task;
        }
        usort($selected, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return array_values($selected);
    }

    // ----------------------------------------------------------------- helpers

    private function fixScore(string $severity): float
    {
        return match ($severity) {
            'critical' => 900,
            'high' => 700,
            default => 400,
        };
    }

    /** @return list<string> */
    private function findingChecklist(string $ruleId): array
    {
        return match (true) {
            str_contains($ruleId, 'title') => ['Sayfaya 50–60 karakter, ana sorguyu içeren benzersiz bir title ekle.'],
            str_contains($ruleId, 'meta-description') => ['140–160 karakterlik, sorguyu ve faydayı içeren meta açıklaması yaz.'],
            str_contains($ruleId, 'noindex') => ['Meta robots noindex etiketini kaldır (SEO eklentisi → sayfa ayarı).'],
            str_contains($ruleId, 'jsonld') => ['JSON-LD bloğunu doğrula (schema.org validator) ve hatalı bloğu düzelt.'],
            str_contains($ruleId, 'canonical') => ['Canonical etiketini sayfanın kendi nihai URL\'sine çevir.'],
            str_contains($ruleId, 'robots') => ['robots.txt\'nin 200 döndüğünü ve Googlebot\'u engellemediğini doğrula.'],
            str_contains($ruleId, 'sitemap') => ['sitemap.xml üret, robots.txt\'de belirt, Search Console\'a gönder.'],
            str_contains($ruleId, 'tls'), str_contains($ruleId, 'https') => ['TLS sertifikasını yenile; HTTP→HTTPS 301 yönlendirmesini doğrula.'],
            default => ['Bulguyu incele ve düzelt; düzeltince görevi "Yapıldı" işaretle.'],
        };
    }

    /**
     * @param  list<string>  $keyParts
     * @param  list<string>  $checklist
     */
    private function task(
        SeoTaskType $type,
        string $ruleId,
        array $keyParts,
        string $severity,
        float $score,
        string $title,
        string $reason,
        array $evidence,
        array $checklist,
        ?string $targetUrl = null,
        int|string|null $offeringId = null,
        ?float $extraClicks = null,
        bool $isNewPage = false,
        ?array $brief = null,
    ): array {
        $signature = implode('|', array_merge([$type->value, $ruleId], $keyParts));

        return [
            'task_key' => hash('sha256', $signature),
            'type' => $type->value,
            'rule_id' => $ruleId,
            'severity' => $severity,
            'priority_score' => round($score, 2),
            'estimated_extra_clicks' => $extraClicks,
            'title' => mb_substr($title, 0, 255),
            'reason' => $reason,
            'evidence' => $evidence,
            'checklist' => array_values($checklist),
            'target_url' => $targetUrl,
            'is_new_page' => $isNewPage,
            'content_brief' => $brief,
            'brand_offering_id' => is_int($offeringId) ? $offeringId : null,
            'service_ref' => $offeringId,
        ];
    }
}
