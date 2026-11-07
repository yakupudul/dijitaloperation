<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\ServiceCategory;
use App\Support\Sales\LeadKinds;

/**
 * Suggests a record's Tür and Sektör from what MoxDOP already knows (no AI, no HTTP): the site scan's schema types and
 * home title, the business name, the site host, the branch, the person's title (Dr., Dt., Uzm.…) and the card note,
 * against the hint words of the kinds list and the sector catalog's names. A confident kind is applied by itself when
 * the record has none; the operator confirms or changes it on the card. Everything it concluded is kept in
 * sales_leads.classification so the card can show why.
 */
final class LeadClassifier
{
    /** Below this the kind is only suggested, not written on the record. */
    public const float APPLY_AT = 0.6;

    /** Schema.org types seen on the home page → kind (strong evidence: the site says what it is). */
    private const array SCHEMA_KINDS = [
        'dentist' => 'clinic', 'medicalclinic' => 'clinic', 'medicalbusiness' => 'clinic', 'medicalorganization' => 'clinic',
        'physician' => 'physician', 'hospital' => 'hospital', 'store' => 'store', 'onlinestore' => 'store', 'manufacturer' => 'factory',
        'professionalservice' => 'service', 'legalservice' => 'service', 'realestateagent' => 'service', 'hotel' => 'service', 'restaurant' => 'service',
        'healthandbeautybusiness' => 'health_other', 'pharmacy' => 'health_other', 'veterinarycare' => 'health_other', 'optician' => 'health_other',
    ];

    /** Words in a sector's catalog name that mean the same as words a record carries ("diş" ↔ dental, dent, dis). */
    private const array SECTOR_SYNONYMS = [
        'dis' => ['dis', 'dent', 'dental', 'ortodonti', 'implant', 'agiz'], 'estetik' => ['estetik', 'aesthetic', 'plastik', 'guzellik', 'botoks', 'dolgu'],
        'psikolog' => ['psikolog', 'psikoloji', 'terapi', 'psikiyatri'], 'psikoloji' => ['psikolog', 'psikoloji', 'terapi'],
        'sac' => ['sac', 'hair', 'ekim'], 'goz' => ['goz', 'eye', 'lazer'], 'fizik' => ['fizyo', 'fizik', 'rehabilitasyon'],
        'hurda' => ['hurda', 'scrap', 'geri donusum'], 'perde' => ['perde', 'tul', 'stor'], 'mobilya' => ['mobilya', 'furniture'],
        'tesisat' => ['tesisat', 'su kacagi', 'petek'], 'hukuk' => ['hukuk', 'avukat', 'avukatlik'], 'emlak' => ['emlak', 'gayrimenkul'],
        'otel' => ['otel', 'hotel', 'pansiyon'], 'nakliyat' => ['nakliyat', 'nakliye', 'evden eve'], 'veteriner' => ['veteriner', 'pet'],
        'diyet' => ['diyetisyen', 'diyet', 'beslenme'], 'isitme' => ['isitme', 'hearing'], 'optik' => ['optik', 'gozluk'],
    ];

    /**
     * @return array{kind: ?string, sector_id: ?int, confidence: float, why: list<string>}
     */
    public function suggest(SalesLead $lead): array
    {
        $scan = is_array($lead->site_scan) ? $lead->site_scan : [];
        $strong = self::fold(implode(' ', array_filter([$lead->company, $lead->website_host, (string) ($scan['home_title'] ?? ''), $lead->branch])));
        $weak = self::fold(mb_substr((string) $lead->notes, 0, 600));
        $scores = [];
        $why = [];
        $bump = function (string $kind, float $by, string $reason) use (&$scores, &$why): void {
            $scores[$kind] = ($scores[$kind] ?? 0) + $by;
            $why[] = $reason;
        };

        foreach ((array) ($scan['schema_types'] ?? []) as $type) {
            $key = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $type));
            if (isset(self::SCHEMA_KINDS[$key])) {
                $bump(self::SCHEMA_KINDS[$key], 1.0, 'Sitenin yapısal verisi: '.$type);
            }
        }
        $kinds = LeadKinds::all();
        foreach ($kinds as $key => $kind) {
            foreach (array_filter(array_map(fn (string $w): string => self::fold($w), explode(',', (string) $kind['hint']))) as $word) {
                if ($word === '') {
                    continue;
                }
                if (self::hasWord($strong, $word)) {
                    $bump($key, 0.7, 'Ad / site / branş: "'.trim($word).'"');
                } elseif (self::hasWord($weak, $word)) {
                    $bump($key, 0.3, 'Not: "'.trim($word).'"');
                }
            }
        }
        if (preg_match('/^\s*(dr|dt|uzm|op|prof|doç|doc)\b\.?/iu', (string) $lead->name) === 1) {
            if (filled($lead->company) && isset($kinds['clinic']) && ! isset($scores['physician'])) {
                $bump('clinic', 0.5, 'Hekim unvanı + işletme adı');
            } elseif (isset($kinds['physician'])) {
                $bump('physician', 0.6, 'Hekim unvanı: '.explode(' ', trim((string) $lead->name))[0]);
            }
        }
        if (isset($kinds['physician'], $scores['physician']) && filled($lead->company) && preg_match('/klinik|poliklinik|hastane|merkez/iu', (string) $lead->company) === 1) {
            // "Dr. X" of "Y Kliniği": the record is the clinic, the person is its doctor.
            $scores['clinic'] = ($scores['clinic'] ?? 0) + $scores['physician'];
            unset($scores['physician']);
        }
        unset($scores[LeadKinds::FALLBACK]);
        arsort($scores);
        $best = array_key_first($scores);
        // One strong word (name, site, branch) is enough to apply; a word in the note alone only suggests.
        $confidence = $best !== null ? min(1.0, round((float) $scores[$best], 2)) : 0.0;

        return ['kind' => $best, 'sector_id' => $this->sector($strong, $weak), 'confidence' => $confidence, 'why' => array_values(array_unique($why))];
    }

    /** Writes the suggestion on the record; a confident kind and a found sector fill empty fields. True when something changed. */
    public function apply(SalesLead $lead): bool
    {
        $current = (array) ($lead->classification ?? []);
        if (($current['confirmed'] ?? false) === true && $lead->kind !== null && $lead->sector_id !== null) {
            return false;
        }
        $s = $this->suggest($lead);
        $lead->classification = array_merge($current, ['kind' => $s['kind'], 'sector_id' => $s['sector_id'], 'confidence' => $s['confidence'],
            'why' => array_slice($s['why'], 0, 6), 'at' => now()->toIso8601String(), 'confirmed' => (bool) ($current['confirmed'] ?? false)]);
        if ($lead->kind === null && $s['kind'] !== null && $s['confidence'] >= self::APPLY_AT) {
            $lead->kind = $s['kind'];
        }
        if ($lead->sector_id === null && $s['sector_id'] !== null) {
            $lead->sector_id = $s['sector_id'];
        }
        $changed = $lead->isDirty(['kind', 'sector_id', 'classification']);
        if ($changed) {
            $lead->save();
        }

        return $changed;
    }

    /** The catalog sector whose name (or a synonym of it) appears in the record's words. */
    private function sector(string $strong, string $weak): ?int
    {
        $best = null;
        $bestScore = 0.0;
        foreach (ServiceCategory::query()->get(['id', 'name', 'normalized_key']) as $category) {
            $score = 0.0;
            $tokens = array_filter(preg_split('/[^a-z0-9]+/', self::fold((string) $category->name)) ?: [], fn (string $t): bool => mb_strlen($t) >= 3 && ! in_array($t, ['ve', 'diger', 'genel', 'hizmet', 'hizmetleri', 'merkezi', 'klinik', 'klinigi'], true));
            $words = [];
            foreach ($tokens as $token) {
                $words[] = $token;
                foreach (self::SECTOR_SYNONYMS[$token] ?? [] as $synonym) {
                    $words[] = $synonym;
                }
            }
            foreach (array_unique($words) as $word) {
                if (self::hasWord($strong, $word)) {
                    $score += 1.0;
                } elseif (self::hasWord($weak, $word)) {
                    $score += 0.4;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $category->id;
            }
        }

        return $bestScore >= 1.0 ? $best : null;
    }

    /** Lowercase ASCII (ı→i, ş→s…), one space between words. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text));
        $text = strtr($text, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $text)));
    }

    private static function hasWord(string $haystack, string $word): bool
    {
        $word = trim($word);

        return $word !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'/', ' '.$haystack.' ') === 1;
    }
}
