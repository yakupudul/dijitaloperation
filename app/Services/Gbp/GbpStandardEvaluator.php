<?php

namespace App\Services\Gbp;

use App\Services\SeoTasks\SeoText;

/**
 * Evaluates the Business Profile standards ("gbp_*" methods of standards.json) over already collected profile data
 * (GbpStandardInput). Pure; no I/O. Each result is pass / fail / review / unknown / not_applicable with a one-sentence
 * Turkish finding and the standard's one-sentence solution. Missing data is never a failure. Q&A is not evaluated:
 * Google retired the Business Profile Q&A API on 2025-11-03.
 */
final class GbpStandardEvaluator
{
    /** Sector code => folded primary category words that fit it. */
    private const array SECTOR_CATEGORIES = [
        'dental' => '/(dis|dentist|dental|ortodont|periodont|endodont|pedodont|agiz|implant)/',
        'healthcare' => '/(klinik|hastane|doktor|hekim|tip|saglik|clinic|hospital|doctor|physician|medical|poliklinik|uzman|merkez)/',
        'medical_aesthetics' => '/(estetik|guzellik|cilt|dermatolog|plastik|aesthetic|cosmetic|spa|lazer)/',
        'legal' => '/(avukat|hukuk|lawyer|attorney|law)/',
    ];

    /** Descriptors that are part of many real clinic names; they alone are not keyword stuffing. */
    private const array GENERIC_NAME_WORDS = [
        'dis', 'klinik', 'klinigi', 'klinikleri', 'poliklinik', 'poliklinigi', 'hastane', 'hastanesi', 'merkez', 'merkezi',
        'agiz', 've', 'sagligi', 'saglik', 'tip', 'dr', 'dt', 'muayenehane', 'muayenehanesi', 'hekimi', 'hekim', 'doktor',
        'avukatlik', 'burosu', 'hukuk', 'ortakligi', 'ltd', 'sti', 'as', 'a', 's', 'the', 'clinic', 'dental', 'center', 'centre',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $standards  enabled gbp_* standards keyed by id
     * @param  array<string, mixed>  $input
     * @return array<string, array{state: string, finding: string, solution: ?string, evidence: mixed}>
     */
    public function evaluate(array $standards, array $input): array
    {
        $out = [];
        foreach ($standards as $id => $standard) {
            $method = (string) $standard['method'];
            if (! str_starts_with($method, 'gbp_')) {
                continue;
            }
            $out[$id] = ($input['location'] ?? null) === null
                ? $this->unknown('Profil verisi henüz toplanmadı.')
                : $this->check(substr($method, 4), $standard, $input);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $input
     * @return array{state: string, finding: string, solution: ?string, evidence: mixed}
     */
    private function check(string $method, array $standard, array $input): array
    {
        $location = $input['location'];
        $failState = ($standard['classification'] ?? 'verified') === 'verified' ? 'fail' : 'review';

        switch ($method) {
            case 'primary_category':
                $category = trim((string) ($location['primary_category'] ?? ''));
                if ($category === '') {
                    return $this->result('fail', $standard, 'Birincil kategori seçilmemiş.');
                }
                $patterns = array_intersect_key(self::SECTOR_CATEGORIES, array_flip((array) ($input['sector_codes'] ?? [])));
                if ($patterns === []) {
                    return $this->pass('Birincil kategori: '.$category.'.');
                }
                $folded = SeoText::fold($category.' '.str_replace(['gcid:', '_'], ' ', $category));
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $folded) === 1) {
                        return $this->pass('Birincil kategori sektöre uygun: '.$category.'.');
                    }
                }

                return $this->result('review', $standard, 'Birincil kategori ("'.$category.'") markanın sektörüne uymuyor.');
            case 'name_no_keywords':
                return $this->name($standard, $input);
            case 'services_complete':
                $services = $input['services'] ?? ['available' => false];
                $offerings = (array) ($input['offerings'] ?? []);
                if ($offerings === []) {
                    return $this->na('Markada tanımlı hizmet yok.');
                }
                if (! ($services['available'] ?? false)) {
                    return $this->unknown('Profil hizmet listesi toplanmadı.');
                }
                $profile = array_merge((array) $services['labels'], (array) ($location['additional_categories'] ?? []), [(string) ($location['primary_category'] ?? '')]);
                $missing = array_values(array_filter($offerings, fn (string $offering): bool => ! $this->listed($offering, $profile)));
                if ($missing === []) {
                    return $this->pass('Markanın '.count($offerings).' hizmeti profilde var.');
                }

                return $this->result($failState, $standard, count($missing).' hizmet profilde yok: '.implode(', ', array_slice($missing, 0, 5)).(count($missing) > 5 ? ' …' : '').'.', null, ['missing' => $missing]);
            case 'attributes_set':
                $attributes = $input['attributes'] ?? ['available' => false];
                if (! ($attributes['available'] ?? false)) {
                    return $this->unknown('Profil özellikleri toplanmadı.');
                }
                $set = count((array) $attributes['set']);
                if ($set >= 3) {
                    return $this->pass($set.' özellik işaretli.');
                }

                return $this->result($failState, $standard, $set === 0 ? 'Hiç özellik işaretlenmemiş.' : 'Yalnız '.$set.' özellik işaretli.', null, ['unset' => array_slice((array) $attributes['unset'], 0, 8)]);
            case 'description':
                $description = trim((string) ($location['description'] ?? ''));
                $problems = [];
                if ($description === '') {
                    return $this->result($failState, $standard, 'Açıklama yok.');
                }
                if (mb_strlen($description) > 750) {
                    $problems[] = mb_strlen($description).' karakter (en fazla 750)';
                }
                if (preg_match('#(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|com\.tr|tr)\b)#i', $description) === 1) {
                    $problems[] = 'bağlantı var';
                }
                foreach (array_slice((array) ($input['description_hits'] ?? []), 0, 2) as $hit) {
                    $problems[] = '"'.$hit['matched'].'" ('.$hit['label'].')';
                }

                return $problems === [] ? $this->pass('Açıklama var ('.mb_strlen($description).' karakter).')
                    : $this->result($failState, $standard, 'Açıklamada sorun: '.implode(', ', $problems).'.');
            case 'hours':
                return $this->hours($standard, $input, $failState);
            case 'nap_matches_site':
                $nap = $input['nap'] ?? null;
                if (! is_array($nap) || ! in_array($nap['state'] ?? null, ['pass', 'fail', 'review'], true)) {
                    return $this->unknown('Sitenin Sayfa Karnesi’nde karşılaştırma yok.');
                }

                return $nap['state'] === 'pass' ? $this->pass((string) $nap['finding'])
                    : $this->result($failState, $standard, (string) $nap['finding']);
            case 'photos_recent':
                $media = $input['media'] ?? ['available' => false];
                if (! ($media['available'] ?? false)) {
                    return $this->unknown('Profil fotoğrafları toplanmadı.');
                }
                $last = $media['last_photo'] ?? null;
                $days = $last !== null ? $this->daysSince((string) $last, (string) $input['today']) : null;
                if ($days !== null && $days <= 90) {
                    return $this->pass('Son fotoğraf '.$days.' gün önce.');
                }

                return $this->result($failState, $standard, $days === null ? 'İşletmenin yüklediği fotoğraf yok.' : 'Son 90 günde fotoğraf yok (son: '.$days.' gün önce).');
            case 'posts_cadence':
                $posts = $input['posts'] ?? ['available' => false];
                if (! ($posts['available'] ?? false)) {
                    return $this->unknown('Gönderi verisi yok.');
                }
                $problems = [];
                $days = ($posts['last_post'] ?? null) !== null ? $this->daysSince((string) $posts['last_post'], (string) $input['today']) : null;
                if ($days === null || $days > 30) {
                    $problems[] = $days === null ? 'gönderi yok' : 'son gönderi '.$days.' gün önce';
                }
                if (($posts['promotional'] ?? []) !== []) {
                    $problems[] = count($posts['promotional']).' gönderide indirim / kampanya ifadesi var';
                }

                return $problems === [] ? $this->pass('Son gönderi '.$days.' gün önce.')
                    : $this->result($failState, $standard, $this->capitalize(implode('; ', $problems)).'.');
            case 'review_velocity':
                $reviews = $input['reviews'] ?? ['available' => false];
                if (! ($reviews['available'] ?? false)) {
                    return $this->unknown('Yorum verisi toplanmadı.');
                }
                if ((int) $reviews['recent_count'] === 0 && (int) $reviews['previous_count'] > 0) {
                    return $this->result($failState, $standard, 'Son 90 günde yeni yorum yok (önceki 90 gün: '.(int) $reviews['previous_count'].').');
                }
                if ($reviews['recent_avg'] !== null && $reviews['baseline_avg'] !== null && (int) $reviews['recent_count'] >= 5
                    && $reviews['baseline_avg'] - $reviews['recent_avg'] >= 0.3) {
                    return $this->result($failState, $standard, sprintf('Son 90 günün puanı %s; önceki yıl %s.', $this->number($reviews['recent_avg']), $this->number($reviews['baseline_avg'])));
                }
                if ((int) $reviews['recent_count'] === 0) {
                    return $this->na('Son 180 günde yorum yok; eğilim hesaplanamıyor.');
                }

                return $this->pass('Son 90 günde '.(int) $reviews['recent_count'].' yeni yorum'.($reviews['recent_avg'] !== null ? ', ortalama '.$this->number($reviews['recent_avg']) : '').'.');
            case 'review_responses':
                $reviews = $input['reviews'] ?? ['available' => false];
                if (! ($reviews['available'] ?? false)) {
                    return $this->unknown('Yorum verisi toplanmadı.');
                }
                $reply = (array) ($reviews['reply'] ?? []);
                $considered = (int) ($reply['considered'] ?? 0);
                if ($considered === 0) {
                    return $this->na('Son 90 günde yanıtlanacak yorum yok.');
                }
                $rate = (int) ($reply['replied'] ?? 0) / $considered;
                $median = $reply['median_hours'] ?? null;
                $problems = [];
                if ($rate < 0.9) {
                    $problems[] = 'yorumların %'.(int) round(100 * (1 - $rate)).'’i yanıtsız';
                }
                if ($median !== null && $median > 48) {
                    $problems[] = 'yanıt süresi ortancası '.$this->duration((float) $median);
                }

                return $problems === [] ? $this->pass('Yorumların %'.(int) round(100 * $rate).'’i yanıtlı'.($median !== null ? ', ortanca '.$this->duration((float) $median) : '').'.')
                    : $this->result($failState, $standard, $this->capitalize(implode('; ', $problems)).'.', null, ['rate' => round($rate, 2), 'median_hours' => $median]);
        }

        return $this->unknown('Bu kontrol için değerlendirici yok.');
    }

    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $input
     * @return array{state: string, finding: string, solution: ?string, evidence: mixed}
     */
    private function name(array $standard, array $input): array
    {
        $title = trim((string) ($input['location']['title'] ?? ''));
        if ($title === '') {
            return $this->unknown('Profil adı yok.');
        }
        $brand = array_flip(explode(' ', SeoText::fold((string) ($input['brand_name'] ?? ''))));
        $locations = array_flip((array) ($input['location_words'] ?? []));
        $services = array_flip((array) ($input['service_words'] ?? []));
        $extra = [];
        foreach (explode(' ', SeoText::fold($title)) as $word) {
            if ($word === '' || isset($brand[$word]) || in_array($word, self::GENERIC_NAME_WORDS, true) || mb_strlen($word) < 3) {
                continue;
            }
            if (isset($locations[$word]) || isset($services[$word])) {
                $extra[] = $word;
            }
        }
        $separator = preg_match('/\s[|\-–—:]\s|\s\|\s?|,/u', $title) === 1;
        if ($extra === [] && ! $separator) {
            return $this->pass('Profil adında semt / hizmet kelimesi yok.');
        }
        $what = $extra !== [] ? 'markada olmayan "'.implode('", "', array_slice(array_unique($extra), 0, 3)).'" kelimesi' : 'ayraçla eklenmiş ek';

        return $this->result('review', $standard, 'Profil adında '.$what.' var; tabeladaki ad değilse askıya alma riski.', null, ['title' => $title]);
    }

    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $input
     * @return array{state: string, finding: string, solution: ?string, evidence: mixed}
     */
    private function hours(array $standard, array $input, string $failState): array
    {
        $location = $input['location'];
        if (in_array(strtoupper((string) ($location['open_status'] ?? '')), ['CLOSED_PERMANENTLY'], true)) {
            return $this->na('Profil kalıcı olarak kapalı.');
        }
        $days = count(array_unique(array_filter(array_map(fn ($period): ?string => is_array($period) ? ($period['openDay'] ?? null) : null, (array) ($location['regular_hours'] ?? [])))));
        if ($days === 0) {
            return $this->result($failState, $standard, 'Çalışma saatleri girilmemiş.', 'Saatler bölümünden haftanın açık günlerini girin.');
        }
        $covered = [];
        foreach ((array) ($location['special_hours'] ?? []) as $period) {
            if (! is_array($period)) {
                continue;
            }
            $start = $this->googleDate($period['startDate'] ?? null);
            $end = $this->googleDate($period['endDate'] ?? null) ?: $start;
            if ($start !== '') {
                $covered[] = [$start, $end];
            }
        }
        $missing = [];
        foreach ((array) ($input['holidays'] ?? []) as $holiday) {
            $hit = false;
            foreach ($holiday['dates'] as $date) {
                foreach ($covered as [$from, $to]) {
                    $hit = $hit || ($date >= $from && $date <= $to);
                }
            }
            if (! $hit) {
                $missing[] = $holiday['name'].' ('.implode(', ', array_map(fn (string $d): string => date('d.m', (int) strtotime($d)), $holiday['dates'])).')';
            }
        }
        if ($missing !== []) {
            return $this->result($failState, $standard, $days.'/7 gün saat var; özel saat yok: '.implode(', ', $missing).'.', null, ['missing' => $missing]);
        }

        return $this->pass($days.'/7 gün saat var'.(($input['holidays'] ?? []) !== [] ? '; yaklaşan tatil için özel saat girili.' : '; yakında resmi tatil yok.'));
    }

    /** @param  list<string>  $profile */
    private function listed(string $offering, array $profile): bool
    {
        foreach ($profile as $text) {
            if ($text !== '' && (SeoText::tokenOverlap($text, $offering) >= 0.6 || SeoText::tokenOverlap($offering, $text) >= 0.6)) {
                return true;
            }
        }

        return false;
    }

    private function googleDate(mixed $date): string
    {
        // Google sends year 0 / day 0 for "every year" or partial dates: not a calendar date.
        if (! is_array($date) || ! isset($date['year'], $date['month'], $date['day'])
            || ! checkdate((int) $date['month'], (int) $date['day'], (int) $date['year']) || (int) $date['year'] < 1900) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day']);
    }

    private function daysSince(string $date, string $today): int
    {
        return (int) max(0, floor((strtotime(substr($today, 0, 10)) - strtotime(substr($date, 0, 10))) / 86400));
    }

    private function duration(float $hours): string
    {
        return $hours < 48 ? (int) round($hours).' saat' : (int) round($hours / 24).' gün';
    }

    private function number(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    private function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * @param  array<string, mixed>  $standard
     * @return array{state: string, finding: string, solution: ?string, evidence: mixed}
     */
    private function result(string $state, array $standard, string $finding, ?string $solution = null, mixed $evidence = null): array
    {
        return ['state' => $state, 'finding' => $finding, 'solution' => $solution ?? (string) $standard['action'], 'evidence' => $evidence];
    }

    /** @return array{state: string, finding: string, solution: ?string, evidence: mixed} */
    private function pass(string $finding): array
    {
        return ['state' => 'pass', 'finding' => $finding, 'solution' => null, 'evidence' => null];
    }

    /** @return array{state: string, finding: string, solution: ?string, evidence: mixed} */
    private function na(string $finding): array
    {
        return ['state' => 'not_applicable', 'finding' => $finding, 'solution' => null, 'evidence' => null];
    }

    /** @return array{state: string, finding: string, solution: ?string, evidence: mixed} */
    private function unknown(string $finding): array
    {
        return ['state' => 'unknown', 'finding' => $finding, 'solution' => null, 'evidence' => null];
    }
}
