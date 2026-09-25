<?php

namespace App\Services\Brain\Clustering;

use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;

/**
 * Rule-based search intent of one query (Turkish + English). Cheap and explainable; AI only relabels clusters it is
 * asked to. Order matters: a price or booking word makes a query transactional even when it also asks "how much".
 */
final class QueryIntent
{
    public const string TRANSACTIONAL = 'transactional';

    public const string LOCAL = 'local';

    public const string COMMERCIAL = 'commercial';

    public const string INFORMATIONAL = 'informational';

    public const string NAVIGATIONAL = 'navigational';

    private const array TRANSACTIONAL_WORDS = ['fiyat', 'ucret', 'randevu', 'kampanya', 'indirim', 'taksit', 'ne kadar', 'kac para', 'price', 'cost', 'book', 'appointment', 'online'];

    private const array COMMERCIAL_WORDS = ['en iyi', 'tavsiye', 'yorum', 'sikayet', 'hangisi', 'karsilastir', 'farki', ' vs ', 'marka', 'best', 'review', 'alternatif'];

    private const array INFORMATIONAL_WORDS = [' mi ', ' mu ', 'midir', 'mudur', 'agri', 'nedir', 'nasil', 'neden', 'nelerdir', 'zarar', 'yan etki', 'sonrasi', 'oncesi', 'suresi', 'kac gun', 'kac yil', 'agrili', 'aci', 'riskleri', 'belirti', 'iyilesme', 'bakim', 'what', 'how', 'why', 'after', 'before', 'recovery', 'side effect'];

    private const array LOCAL_WORDS = ['yakin', 'yakinimda', 'near me', 'nerede', 'adres'];

    public static function of(string $query): string
    {
        $folded = ' '.SeoText::fold($query).' ';
        foreach (self::TRANSACTIONAL_WORDS as $word) {
            if (str_contains($folded, ' '.$word) || str_contains($folded, $word.' ')) {
                return self::TRANSACTIONAL;
            }
        }
        $place = LocationOptions::strip($query)['text'] !== trim($query);
        foreach (self::LOCAL_WORDS as $word) {
            if (str_contains($folded, $word)) {
                return self::LOCAL;
            }
        }
        if ($place) {
            return self::LOCAL;
        }
        // "zirkonyum mu porselen mi": two alternatives compared.
        if (preg_match('/\b(mi|mu)\b.+\b(mi|mu)\b/', $folded) === 1) {
            return self::COMMERCIAL;
        }
        foreach (self::COMMERCIAL_WORDS as $word) {
            if (str_contains($folded, $word)) {
                return self::COMMERCIAL;
            }
        }
        foreach (self::INFORMATIONAL_WORDS as $word) {
            if (str_contains($folded, $word)) {
                return self::INFORMATIONAL;
            }
        }

        return self::TRANSACTIONAL;
    }

    /**
     * Dominant intent of weighted queries.
     *
     * @param  list<array{text: string, weight: float}>  $queries
     */
    public static function dominant(array $queries): string
    {
        $scores = [];
        foreach ($queries as $query) {
            $intent = self::of($query['text']);
            $scores[$intent] = ($scores[$intent] ?? 0) + max(1.0, $query['weight']);
        }
        arsort($scores);

        return (string) (array_key_first($scores) ?? self::INFORMATIONAL);
    }
}
