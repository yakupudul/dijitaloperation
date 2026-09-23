<?php

namespace App\Services\Advisor\Gbp;

use App\Enums\AdvisorCategory;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Advisor\Support\BuildsAdvisorItems;
use App\Services\SeoTasks\SeoText;

/**
 * Google Business Profile advisor rules (pure). Review replies are out of scope by decision; posting and
 * photo suggestions only appear when the profile actually earns interactions. Silent without data.
 */
final class GbpAdvisorRuleEngine
{
    use BuildsAdvisorItems;

    private const array METRIC_LABELS = [
        'CALL_CLICKS' => 'Arama tıklaması',
        'WEBSITE_CLICKS' => 'Web sitesi tıklaması',
        'BUSINESS_DIRECTION_REQUESTS' => 'Yol tarifi',
        'BUSINESS_CONVERSATIONS' => 'Mesaj',
        'BUSINESS_BOOKINGS' => 'Rezervasyon',
        'IMPRESSIONS' => 'Görüntülenme (Arama + Haritalar)',
    ];

    /** @var array<string, mixed> */
    private array $cfg = [];

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, silenced: list<string>, summary: array<string, mixed>}
     */
    public function evaluate(array $input): array
    {
        $this->cfg = (array) config('moxdop-advisor.gbp', []);
        if (! ($input['bound'] ?? false)) {
            return ['items' => [], 'silenced' => ['not_bound'], 'summary' => ['reason' => 'not_bound']];
        }
        if (($input['location'] ?? null) === null) {
            return ['items' => [], 'silenced' => ['no_campaign_data'], 'summary' => ['reason' => 'no_campaign_data']];
        }
        $input['account'] = ['cost' => 1.0];

        $items = array_merge(
            $this->openStatus($input),
            $this->profileGaps($input),
            $this->keywordServiceGaps($input),
            $this->performanceDrop($input),
            $this->ratingTrend($input),
            $this->photoFreshness($input),
            $this->websiteUtm($input),
        );
        usort($items, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);
        $max = (int) ($this->cfg['max_open'] ?? 6);
        $kept = [];
        $silenced = [];
        foreach ($items as $item) {
            if (count($kept) < $max || $item['severity'] === 'critical') {
                $kept[] = $item;
            } else {
                $silenced[] = $item['rule_id'];
            }
        }
        $actions = $this->actions($input['performance']['current'] ?? []);

        return ['items' => $kept, 'silenced' => $silenced, 'summary' => ['reason' => null, 'actions_28d' => $actions, 'waste' => 0.0]];
    }

    protected function channelKey(): string
    {
        return 'google_business_profile';
    }

    // ------------------------------------------------------------------ status & completeness

    /** @return list<array<string, mixed>> */
    private function openStatus(array $input): array
    {
        $status = strtoupper((string) ($input['location']['open_status'] ?? ''));
        if (! in_array($status, ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY'], true)) {
            return [];
        }

        return [$this->item(
            input: $input, category: AdvisorCategory::Profile, ruleId: 'profile-closed', keyParts: [$status], severity: 'critical', impact: null,
            impactLabel: 'Profil aramalarda "kapalı" görünüyor',
            title: $status === 'CLOSED_PERMANENTLY' ? 'Profil "kalıcı olarak kapandı" görünüyor' : 'Profil "geçici olarak kapalı" görünüyor',
            reason: 'Google profili kapalı olarak işaretli. Müşteriler aramada işletmeyi kapalı görür, arama ve yol tarifi düşer. İşletme açıksa hemen düzelt.',
            evidence: ['open_status' => $status],
            checklist: ['İşletme Profili → Profili düzenle → Saatler → Açık olarak işaretle.', 'Değişiklikten sonra Google onayını takip et.'],
            copyText: null, baseline: null,
        )];
    }

    /** @return list<array<string, mixed>> */
    private function profileGaps(array $input): array
    {
        $location = $input['location'];
        $issues = [];
        $severity = 'medium';
        $minChars = (int) ($this->cfg['description_min_chars'] ?? 250);
        $description = trim((string) $location['description']);
        if ($description === '') {
            $issues[] = ['issue' => 'Açıklama yok.', 'fix' => sprintf('Hizmetleri ve bölgeyi anlatan %d–750 karakterlik bir açıklama yaz (AI taslağı hazırlanabilir).', $minChars)];
        } elseif (mb_strlen($description) < $minChars) {
            $issues[] = ['issue' => sprintf('Açıklama kısa (%d karakter).', mb_strlen($description)), 'fix' => 'Açıklamayı ana hizmetler ve hizmet bölgesiyle genişlet (en fazla 750 karakter).'];
        }
        if ($location['additional_categories'] === []) {
            $issues[] = ['issue' => 'Yalnızca birincil kategori var ('.($location['primary_category'] ?: '—').').', 'fix' => 'Sunduğun diğer hizmetlere uyan 2–4 ek kategori ekle.'];
        }
        if ($location['regular_hours'] === []) {
            $issues[] = ['issue' => 'Çalışma saatleri girilmemiş.', 'fix' => 'Çalışma saatlerini ekle; "şu an açık" filtresinde görünmek için gerekli.'];
            $severity = 'high';
        }
        if ($location['phones'] === []) {
            $issues[] = ['issue' => 'Telefon numarası yok.', 'fix' => 'Birincil telefonu ekle; arama tıklaması buradan gelir.'];
            $severity = 'high';
        }
        $website = (string) ($location['website_uri'] ?? '');
        if ($website === '') {
            $issues[] = ['issue' => 'Web sitesi bağlantısı yok.', 'fix' => 'Web sitesini (ana sayfa ya da ilgili hizmet sayfası) ekle.'];
        } else {
            foreach (AdvisorWebsiteReader::landingIssues($website, $input['website']['pages'] ?? []) as $problem) {
                $issues[] = ['issue' => 'Web sitesi bağlantısı: '.$problem, 'fix' => 'Profildeki bağlantıyı çalışan, son adrese çevir.'];
                if (str_starts_with($problem, 'Sayfa hata')) {
                    $severity = 'high';
                }
            }
        }
        if (($input['services']['available'] ?? false) && $input['services']['labels'] === [] && $location['can_modify_services']) {
            $issues[] = ['issue' => 'Hizmet listesi boş.', 'fix' => 'Markanın hizmetlerini profile hizmet olarak ekle (aşağıdaki "profilde olmayan hizmetler" önerisine bak).'];
        }
        if (($input['media']['available'] ?? false)) {
            $missing = array_diff(['COVER', 'LOGO'], $input['media']['categories']);
            if ($missing !== []) {
                $issues[] = ['issue' => 'Eksik görsel: '.implode(', ', array_map(static fn (string $c): string => $c === 'COVER' ? 'kapak fotoğrafı' : 'logo', $missing)).'.', 'fix' => 'Kapak fotoğrafı ve logo yükle.'];
            }
        }
        if ($location['has_google_updated']) {
            $issues[] = ['issue' => 'Google profil bilgisinde değişiklik yaptı ya da önerdi.', 'fix' => 'İşletme Profili\'nde "Google güncellemeleri"ni aç; yanlışsa reddet.'];
        }
        $unset = array_slice($input['attributes']['unset'] ?? [], 0, (int) ($this->cfg['attribute_suggestions'] ?? 8));
        if ($issues === [] && $unset === []) {
            return [];
        }

        return [$this->item(
            input: $input, category: AdvisorCategory::Profile, ruleId: 'profile-gaps', keyParts: [], severity: $severity, impact: null,
            impactLabel: sprintf('%d eksik', count($issues) + ($unset !== [] ? 1 : 0)),
            title: sprintf('Profil eksikleri (%d)', count($issues) + ($unset !== [] ? 1 : 0)),
            reason: 'Eksiksiz profiller yerel aramada daha sık ve daha üstte gösterilir. Bu liste en son toplanan profil verisinden çıkarıldı.'.($description === '' || mb_strlen($description) < $minChars ? ' Açıklama için AI taslağı hazırlatabilirsin.' : ''),
            evidence: ['issues' => $issues, 'attributes' => $unset, 'current_description' => $description !== '' ? $description : null, 'primary_category' => $location['primary_category']],
            checklist: array_values(array_unique(array_merge(array_column($issues, 'fix'), $unset !== [] ? ['İşletmene uyan özellikleri işaretle (ör. '.implode(', ', array_slice($unset, 0, 3)).').'] : []))),
            copyText: null, baseline: ['issues' => count($issues)],
        )];
    }

    // ------------------------------------------------------------------ search keywords ↔ services

    /** @return list<array<string, mixed>> */
    private function keywordServiceGaps(array $input): array
    {
        $offerings = $input['offerings'] ?? [];
        $profileTexts = array_values(array_filter(array_merge(
            $input['services']['labels'] ?? [],
            $input['location']['additional_categories'] ?? [],
            [(string) ($input['location']['primary_category'] ?? '')],
        )));
        $servicesKnown = (bool) ($input['services']['available'] ?? false);
        $inProfile = function (array $offering) use ($profileTexts): bool {
            foreach (array_merge([$offering['name']], $offering['names'] ?? []) as $name) {
                foreach ($profileTexts as $text) {
                    if (SeoText::tokenOverlap($text, (string) $name) >= 0.6 || SeoText::tokenOverlap((string) $name, $text) >= 0.6) {
                        return true;
                    }
                }
            }

            return false;
        };

        $rows = [];
        $missingOfferings = [];
        $keywords = $input['keywords'] ?? ['available' => false, 'items' => []];
        if ($keywords['available']) {
            $min = (int) ($this->cfg['keyword_min_impressions'] ?? 30);
            $brandTokens = $this->brandTokens($input);
            foreach ($keywords['items'] as $row) {
                if ($row['impressions'] < $min || $this->isBrand($row['keyword'], $brandTokens)) {
                    continue;
                }
                $matched = null;
                foreach ($offerings as $offering) {
                    foreach (array_merge([$offering['name']], $offering['names'] ?? [], $offering['keywords'] ?? []) as $text) {
                        if (mb_strlen((string) $text) >= 3 && (SeoText::containsPhrase($row['keyword'], (string) $text) || SeoText::tokenOverlap($row['keyword'], (string) $text) >= 0.8)) {
                            $matched = $offering;
                            break 2;
                        }
                    }
                }
                if ($matched === null) {
                    if ($row['impressions'] >= 2 * $min) {
                        $rows[] = ['keyword' => $row['keyword'], 'impressions' => $row['impressions'], 'offering' => null, 'note' => 'Markada bu hizmet tanımlı değil: sunuyorsan ekle'];
                    }

                    continue;
                }
                if ($servicesKnown && ! $inProfile($matched)) {
                    $rows[] = ['keyword' => $row['keyword'], 'impressions' => $row['impressions'], 'offering' => $matched['name'], 'note' => 'Profilde hizmet olarak yok'];
                    $missingOfferings[$matched['name']] = true;
                }
            }
        }

        $items = [];
        if ($rows !== []) {
            $rows = array_slice($rows, 0, (int) ($this->cfg['keyword_max_items'] ?? 20));
            $items[] = $this->item(
                input: $input, category: AdvisorCategory::Growth, ruleId: 'keyword-service-gaps', keyParts: [], severity: 'medium', impact: null,
                impactLabel: sprintf('%s görüntülenme / %d ay bu aramalardan', number_format(array_sum(array_column($rows, 'impressions')), 0, ',', '.'), $keywords['months']),
                title: sprintf('İnsanlar seni bu aramalarla buluyor ama profilde karşılığı yok (%d)', count($rows)),
                reason: 'Google\'ın aylık arama kelimesi raporuna göre profil bu aramalarda gösteriliyor. Eşleşen hizmet profilde yoksa Google profili bu aramalar için daha az alakalı bulur; markada hiç yoksa yeni bir hizmet fırsatı olabilir.',
                evidence: ['keywords' => $rows, 'months' => $keywords['months']],
                checklist: ['"Profilde hizmet olarak yok" satırlarındaki hizmetleri İşletme Profili → Hizmetler\'e ekle (kısa açıklamayla).', 'Markada tanımlı olmayan aramalar için: bu hizmeti gerçekten sunuyorsan markaya ve web sitesine de ekle.', 'Aynı hizmetleri web sitesinde ayrı sayfa/başlık olarak anlat (SEO Görevleri ile uyumlu).'],
                copyText: $missingOfferings !== [] ? implode("\n", array_keys($missingOfferings)) : null,
                baseline: ['keywords' => count($rows)],
            );
        }

        if ($servicesKnown && $offerings !== []) {
            $priority = array_filter($offerings, static fn (array $o): bool => (bool) $o['is_priority']);
            $candidates = $priority !== [] ? $priority : $offerings;
            $missing = [];
            foreach ($candidates as $offering) {
                if (! $inProfile($offering) && ! isset($missingOfferings[$offering['name']])) {
                    $missing[] = $offering['name'];
                }
            }
            if ($missing !== []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Profile, ruleId: 'site-profile-services', keyParts: [], severity: 'low', impact: null,
                    impactLabel: sprintf('%d hizmet profilde yok', count($missing)),
                    title: sprintf('Markanın %shizmetleri profilde yok (%d)', $priority !== [] ? 'öncelikli ' : '', count($missing)),
                    reason: 'Markada tanımlı olan bu hizmetler İşletme Profili\'nin hizmet listesinde ya da kategorilerinde görünmüyor. Site ile profilin aynı hizmetleri söylemesi yerel aramada tutarlılık sağlar.',
                    evidence: ['missing_services' => $missing, 'profile_services' => array_slice($profileTexts, 0, 30)],
                    checklist: ['İşletme Profili → Hizmetler → "Özel hizmet ekle" ile listedeki hizmetleri ekle.', 'Her hizmete 1–2 cümlelik açıklama yaz.'],
                    copyText: implode("\n", $missing),
                    baseline: ['missing' => count($missing)],
                );
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------ performance, reviews, photos

    /** @return list<array<string, mixed>> */
    private function performanceDrop(array $input): array
    {
        $performance = $input['performance'] ?? ['available' => false];
        if (! ($performance['available'] ?? false) || ($performance['previous'] ?? []) === []) {
            return [];
        }
        $before = $this->actions($performance['previous']);
        $after = $this->actions($performance['current']);
        if ($before < (int) ($this->cfg['min_actions_before'] ?? 30)) {
            return [];
        }
        $drop = 1 - $after / $before;
        if ($drop < (float) ($this->cfg['action_drop'] ?? 0.3)) {
            return [];
        }
        $metrics = [];
        foreach (array_merge((array) ($this->cfg['action_metrics'] ?? []), ['IMPRESSIONS']) as $metric) {
            $b = $metric === 'IMPRESSIONS' ? $this->impressions($performance['previous']) : (int) ($performance['previous'][$metric] ?? 0);
            $a = $metric === 'IMPRESSIONS' ? $this->impressions($performance['current']) : (int) ($performance['current'][$metric] ?? 0);
            if ($b > 0 || $a > 0) {
                $metrics[] = ['metric' => self::METRIC_LABELS[$metric] ?? $metric, 'before' => $b, 'after' => $a, 'change' => $b > 0 ? (int) round(($a / $b - 1) * 100) : null];
            }
        }
        $impressionsDrop = $this->impressions($performance['previous']) > 0 ? 1 - $this->impressions($performance['current']) / $this->impressions($performance['previous']) : 0.0;

        return [$this->item(
            input: $input, category: AdvisorCategory::Growth, ruleId: 'profile-actions-drop', keyParts: [], severity: $drop >= 0.5 ? 'high' : 'medium', impact: null,
            impactLabel: sprintf('%d → %d müşteri etkileşimi / %d gün', $before, $after, $performance['days']),
            title: sprintf('Profil etkileşimi %%%d düştü', (int) round($drop * 100)),
            reason: sprintf(
                'Son %d günde arama, web sitesi, yol tarifi ve mesaj toplamı önceki döneme göre %%%d azaldı. %s',
                $performance['days'], (int) round($drop * 100),
                $impressionsDrop >= 0.2 ? 'Görüntülenme de düştü: profil aramalarda daha az gösteriliyor (kategori, saat, askıya alma veya rakip değişikliği olabilir).' : 'Görüntülenme benzer: görenler daha az aksiyon alıyor (puan, fotoğraf, saat ya da bilgi değişikliğini kontrol et).',
            ),
            evidence: ['metrics' => $metrics],
            checklist: ['Profilin askıya alınmadığını ve "açık" göründüğünü kontrol et.', 'Son dönemde kategori, saat, adres veya telefon değişti mi bak (Google güncellemeleri dahil).', 'Yeni olumsuz yorum ve puan değişimini kontrol et.'],
            copyText: null, baseline: ['actions_before' => $before, 'actions_after' => $after],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function ratingTrend(array $input): array
    {
        $reviews = $input['reviews'] ?? ['available' => false];
        if (! ($reviews['available'] ?? false) || $reviews['recent_count'] < (int) ($this->cfg['rating_min_reviews'] ?? 5) || $reviews['previous_avg'] === null) {
            return [];
        }
        $drop = $reviews['previous_avg'] - $reviews['recent_avg'];
        if ($drop < (float) ($this->cfg['rating_drop'] ?? 0.3)) {
            return [];
        }

        return [$this->item(
            input: $input, category: AdvisorCategory::Profile, ruleId: 'rating-trend', keyParts: [], severity: 'medium', impact: null,
            impactLabel: sprintf('Puan %s → %s', number_format($reviews['previous_avg'], 1, ',', '.'), number_format($reviews['recent_avg'], 1, ',', '.')),
            title: 'Son yorumlarda puan düşüyor',
            reason: sprintf(
                'Son 90 günün %d yorumunun ortalaması %s; önceki yılın ortalaması %s idi.%s Yorumlardaki ortak şikâyet genellikle hizmette düzeltilmesi gereken bir şeye işaret eder.',
                $reviews['recent_count'], number_format($reviews['recent_avg'], 1, ',', '.'), number_format($reviews['previous_avg'], 1, ',', '.'),
                $reviews['unanswered_recent'] > 0 ? sprintf(' Bunlardan %d tanesi cevapsız.', $reviews['unanswered_recent']) : '',
            ),
            evidence: ['recent_reviews' => $reviews['recent_count'], 'unanswered_recent' => $reviews['unanswered_recent']],
            checklist: ['Son olumsuz yorumları oku ve ortak konuyu bul.', 'Konuyu işletmeyle paylaş; yorumları nazikçe ve çözüm odaklı yanıtla.', 'Memnun müşterilerden yorum istemeyi süreç haline getir.'],
            copyText: null, baseline: ['recent_avg' => round($reviews['recent_avg'], 2)],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function photoFreshness(array $input): array
    {
        $media = $input['media'] ?? ['available' => false];
        $actions = $this->actions($input['performance']['current'] ?? []);
        if (! ($media['available'] ?? false) || $actions < (int) ($this->cfg['photo_min_actions_28d'] ?? 20)) {
            return [];
        }
        $staleDays = (int) ($this->cfg['photo_stale_days'] ?? 120);
        $last = $media['last_photo'];
        if ($last !== null && strtotime($input['period']['end']) - strtotime($last) < $staleDays * 86400) {
            return [];
        }

        return [$this->item(
            input: $input, category: AdvisorCategory::Profile, ruleId: 'photo-freshness', keyParts: [], severity: 'low', impact: null,
            impactLabel: sprintf('%d etkileşim / %d gün alan profil', $actions, $input['performance']['days']),
            title: $last === null ? 'Profilde işletmenin kendi fotoğrafı yok' : sprintf('Son fotoğraf %s tarihli', date('d.m.Y', strtotime($last))),
            reason: sprintf('Profil ayda düzenli etkileşim alıyor ama %s. Güncel iç/dış mekân ve ekip fotoğrafları tıklanma oranını artırır.', $last === null ? 'işletme hiç fotoğraf yüklememiş' : sprintf('%d günden uzun süredir yeni fotoğraf eklenmemiş', $staleDays)),
            evidence: ['photos' => $media['photos'], 'last_photo' => $last],
            checklist: ['3–5 güncel fotoğraf yükle: dış cephe, iç mekân, ekip, hizmet anı.', 'Stok fotoğraf kullanma.'],
            copyText: null, baseline: null,
        )];
    }

    /** @return list<array<string, mixed>> */
    private function websiteUtm(array $input): array
    {
        $website = (string) ($input['location']['website_uri'] ?? '');
        if ($website === '' || str_contains($website, 'utm_')) {
            return [];
        }
        $suggested = $website.(str_contains($website, '?') ? '&' : '?').(string) ($this->cfg['utm'] ?? 'utm_source=google&utm_medium=organic&utm_campaign=gbp');

        return [$this->item(
            input: $input, category: AdvisorCategory::Measurement, ruleId: 'website-utm', keyParts: [], severity: 'low', impact: null,
            impactLabel: 'GA4\'te profil trafiği ayrı görünür',
            title: 'Profildeki web sitesi bağlantısına UTM ekle',
            reason: 'Şu an profilden gelen ziyaretler GA4\'te genel "Google organik" içinde kayboluyor. UTM ile profilin getirdiği ziyaret ve dönüşüm ayrı ölçülür.',
            evidence: ['current_url' => $website],
            checklist: ['İşletme Profili → Profili düzenle → Web sitesi alanına aşağıdaki adresi yapıştır.'],
            copyText: $suggested, baseline: null,
        )];
    }

    // ------------------------------------------------------------------ helpers

    private function actions(array $metrics): int
    {
        $sum = 0;
        foreach ((array) ($this->cfg['action_metrics'] ?? []) as $metric) {
            $sum += (int) ($metrics[$metric] ?? 0);
        }

        return $sum;
    }

    private function impressions(array $metrics): int
    {
        $sum = 0;
        foreach ($metrics as $metric => $value) {
            if (str_starts_with((string) $metric, 'BUSINESS_IMPRESSIONS_')) {
                $sum += (int) $value;
            }
        }

        return $sum;
    }

    /** @return list<string> */
    private function brandTokens(array $input): array
    {
        $generic = (array) config('moxdop-seo-tasks.geo.generic_name_words', []);
        $tokens = array_merge(explode(' ', SeoText::fold((string) ($input['asset']['brand_name'] ?? ''))), explode(' ', SeoText::fold((string) ($input['location']['title'] ?? ''))));

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 4 && ! in_array($t, $generic, true))));
    }

    private function isBrand(string $keyword, array $brandTokens): bool
    {
        $folded = ' '.SeoText::fold($keyword).' ';
        foreach ($brandTokens as $token) {
            if (str_contains($folded, ' '.$token.' ') || str_contains(str_replace(' ', '', $folded), $token)) {
                return true;
            }
        }

        return false;
    }
}
