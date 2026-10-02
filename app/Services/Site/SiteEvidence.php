<?php

namespace App\Services\Site;

use App\Services\SeoTasks\SeoText;

/**
 * Grounding checks for AI text of the website screen: every URL must be one of the data pack's URLs and every number
 * of two or more digits must be one of the pack's numbers (rounded, dot / comma tolerant). Pure; no I/O.
 */
final class SiteEvidence
{
    /** @var array<string, true> */
    private array $urls = [];

    /** @var array<string, true> */
    private array $numbers = [];

    /** @var list<string> folded texts quotes may come from */
    private array $texts = [];

    /**
     * @param  list<string>  $urls
     * @param  list<int|float|string>  $numbers
     * @param  list<string>  $texts
     */
    public function __construct(array $urls = [], array $numbers = [], array $texts = [])
    {
        foreach ($urls as $url) {
            if (trim($url) !== '') {
                $this->urls[SeoText::urlKey($url)] = true;
            }
        }
        foreach ([...$numbers, (int) date('Y'), (int) date('Y') - 1, (int) date('Y') + 1] as $number) {
            $this->addNumber($number);
        }
        foreach ($texts as $text) {
            $this->texts[] = SeoText::fold($text);
        }
    }

    public function addNumber(int|float|string|null $number): void
    {
        if ($number === null || $number === '' || ! is_numeric(str_replace(',', '.', (string) $number))) {
            return;
        }
        $value = (float) str_replace(',', '.', (string) $number);
        foreach ([$value, round($value), round($value, 1), round($value * 100), round($value * 100, 1)] as $variant) {
            $this->numbers[self::key($variant)] = true;
        }
    }

    /**
     * Every number in the pack's arrays (recursively).
     *
     * @param  array<mixed>  $pack
     */
    public function addNumbersFrom(array $pack): void
    {
        array_walk_recursive($pack, function (mixed $value): void {
            if (is_int($value) || is_float($value)) {
                $this->addNumber($value);
            }
        });
    }

    public function knowsUrl(string $url): bool
    {
        return isset($this->urls[SeoText::urlKey($url)]);
    }

    /** "1.234" / "1.234,5" (Turkish thousands), "4,5" and "4.5" are all read. */
    public function knowsNumber(string $number): bool
    {
        $candidates = [str_replace(',', '.', $number)];
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $number) === 1) {
            $candidates[] = str_replace(['.', ','], ['', '.'], $number);
        }
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && isset($this->numbers[self::key((float) $candidate)])) {
                return true;
            }
        }

        return false;
    }

    /** A quote is grounded when its folded text appears in one of the pack's texts. */
    public function knowsQuote(string $quote): bool
    {
        $folded = SeoText::fold($quote);
        if (mb_strlen($folded) < 3) {
            return false;
        }
        foreach ($this->texts as $text) {
            if (str_contains($text, $folded)) {
                return true;
            }
        }

        return false;
    }

    /** No unknown URL and no unknown number (≥ 2 digits) in the text. */
    public function grounded(string $text): bool
    {
        preg_match_all('#https?://[^\s"\'<>)]+#i', $text, $urls);
        foreach ($urls[0] as $url) {
            if (! $this->knowsUrl(rtrim($url, '.,;:'))) {
                return false;
            }
        }
        $withoutUrls = (string) preg_replace('#https?://[^\s"\'<>)]+#i', ' ', $text);
        preg_match_all('/(?<![\p{L}\d])\d[\d.,]*\d(?![\p{L}\d])/u', $withoutUrls, $numbers);
        foreach ($numbers[0] as $number) {
            if (! $this->knowsNumber(rtrim($number, '.,'))) {
                return false;
            }
        }

        return true;
    }

    private static function key(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
