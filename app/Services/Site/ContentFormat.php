<?php

namespace App\Services\Site;

use App\Services\SeoTasks\SeoText;

/**
 * Yazının biçimi ve uzunluğu konuya göre (yakup, 2026-10-15 "yazıların uzunluğu hep aynı"): the question the article
 * answers decides its shape and length, not one template. A quick question gets a short direct answer, a process its
 * steps, a comparison a table, the cluster's main page the whole subject. When the pages that do well in the cluster
 * elsewhere (benchmarks) are longer, the range follows them; the writer also looks at Google's first results itself.
 */
final class ContentFormat
{
    /** @var array<string, array{label: string, words: array{0: int, 1: int}, shape: string}> */
    public const array INTENTS = [
        'quick' => ['label' => 'Kısa soru', 'words' => [450, 750],
            'shape' => 'The first paragraph answers the question in 2–3 sentences; then the few things that change the answer, each briefly. No long background.'],
        'process' => ['label' => 'Süreç', 'words' => [900, 1400],
            'shape' => 'The steps in order as an <ol> (what happens, how long it takes, what the person feels or does), then before / after care.'],
        'comparison' => ['label' => 'Karşılaştırma', 'words' => [1100, 1800],
            'shape' => 'A <table> comparing the options on the points that decide (how it works, who it suits, duration, care), then who each option suits.'],
        'cost' => ['label' => 'Maliyet', 'words' => [900, 1300],
            'shape' => 'What the cost depends on, one heading per factor, and why two people get different plans; never a price unless DATA_JSON states it.'],
        'guide' => ['label' => 'Rehber', 'words' => [900, 1400],
            'shape' => 'Answer, then the points a person weighs when deciding, the fears or myths answered calmly, and when to see the expert.'],
        'pillar' => ['label' => 'Ana sayfa', 'words' => [1500, 2500],
            'shape' => 'The whole subject of the cluster: what it is, who it suits, the process, options, recovery and care, with a short section per sub-question that links to the site\'s own page on it.'],
    ];

    /** An article shorter than this share of the range's minimum is written again once. */
    public const float SHORT_SHARE = 0.75;

    /**
     * @param  list<array{word_count?: int}>  $benchmarks
     * @return array{intent: string, label: string, words: array{0: int, 1: int}, shape: string}
     */
    public static function for(string $title, ?string $query, string $pageType, ?string $angle, array $benchmarks = []): array
    {
        $intent = self::intent($title, $query, $pageType, $angle);
        $format = self::INTENTS[$intent];
        [$min, $max] = $format['words'];
        $counts = array_values(array_filter(array_map(fn (array $b): int => (int) ($b['word_count'] ?? 0), $benchmarks), fn (int $n): bool => $n >= 200));
        if ($counts !== []) {
            sort($counts);
            $median = $counts[intdiv(count($counts), 2)];
            // The bar follows the pages that do well, within reason: never under the shape's own minimum, at most 1.5× its maximum.
            $max = min((int) round($max * 1.5), max($max, (int) round($median * 1.1)));
            $min = min($max - 200, max($min, (int) round($median * 0.8)));
        }

        return ['intent' => $intent, 'label' => $format['label'], 'words' => [$min, $max], 'shape' => $format['shape']];
    }

    public static function intent(string $title, ?string $query, string $pageType, ?string $angle): string
    {
        if (in_array($pageType, ['hizmet', 'lokasyon'], true)) {
            return 'pillar';
        }
        // Title and search are read apart: "… yapılır mı?" + "… yapılır mı" is not an "A mı B mi" comparison.
        $texts = array_map(fn (string $t): string => ' '.SeoText::fold($t).' ', array_filter([$title, (string) $query]));
        $has = fn (string $pattern): bool => collect($texts)->contains(fn (string $text): bool => preg_match($pattern, $text) === 1);

        return match (true) {
            $angle === 'comparison' || $has('/ (fark|farki|farklari|karsilastir\w*|vs|versus|yoksa|hangisi|difference|compare\w*) /') || $has('/ m[iu]( [^ ]+){1,4} m[iu] /') => 'comparison',
            $has('/ (fiyat\w*|maliyet\w*|ucret\w*|para|price|cost\w*) /') => 'cost',
            $angle === 'process' || $has('/ (nasil yapilir|nasil uygulanir|nasil ilerler|asama\w*|adim\w*|surec\w*|how is|how are|steps?) /') => 'process',
            $angle === 'expert_answer' || $has('/ (kac gun|kac saat|kac hafta|kac ay|kac seans|ne zaman|ne yenir|yenir mi|olur mu|normal mi|gecer mi|how long|when) /') => 'quick',
            default => 'guide',
        };
    }

    /** Words of the article body (HTML). */
    public static function words(string $html): int
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param  array{words: array{0: int, 1: int}}  $format */
    public static function tooShort(int $words, array $format): bool
    {
        return $words < (int) floor($format['words'][0] * self::SHORT_SHARE);
    }
}
