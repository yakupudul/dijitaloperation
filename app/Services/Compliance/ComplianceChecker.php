<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRule;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * Pure text / targeting checks against compliance rules. Phrases match on folded Turkish text at word
 * boundaries, suffix-tolerant (case, diacritics and punctuation insensitive); patterns with symbols
 * (e.g. "%100") match the lower-cased raw text.
 */
final class ComplianceChecker
{
    /**
     * @param  Collection<int, ComplianceRule>  $rules
     * @return list<array{rule: ComplianceRule, matched: string, excerpt: string}>
     */
    public function checkText(string $text, Collection $rules, string $source): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        $folded = ' '.SeoText::fold($text).' ';
        $lower = mb_strtolower($text);
        $hits = [];
        foreach ($rules as $rule) {
            if (! $rule->active || ! $rule->appliesTo($source) || $rule->kind === ComplianceRuleKinds::TARGETING) {
                continue;
            }
            $found = null;
            foreach ((array) $rule->patterns as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern === '') {
                    continue;
                }
                if (preg_match('/[^\p{L}\p{N}\s\'’]/u', $pattern) === 1) {
                    if (str_contains($lower, mb_strtolower($pattern))) {
                        $found = $pattern;
                        break;
                    }

                    continue;
                }
                $needle = SeoText::fold($pattern);
                // Exact folded phrase, or the same phrase with Turkish suffixes (garantili, indirimli, liderimiz).
                if ($needle !== '' && (str_contains($folded, ' '.$needle.' ') || SeoText::matchesPhrase($text, $pattern))) {
                    $found = $pattern;
                    break;
                }
            }
            if ($rule->kind === ComplianceRuleKinds::FORBIDDEN && $found !== null) {
                $hits[] = ['rule' => $rule, 'matched' => $found, 'excerpt' => $this->excerpt($text, $found)];
            }
            if ($rule->kind === ComplianceRuleKinds::REQUIRED && $found === null) {
                $hits[] = ['rule' => $rule, 'matched' => '(eksik) '.implode(' / ', (array) $rule->patterns), 'excerpt' => mb_substr($text, 0, 200)];
            }
        }

        return $hits;
    }

    /**
     * Meta ad set targeting (the stored targeting JSON).
     *
     * @param  array<string, mixed>  $targeting
     * @param  Collection<int, ComplianceRule>  $rules
     * @return list<array{rule: ComplianceRule, matched: string, excerpt: string}>
     */
    public function checkTargeting(array $targeting, Collection $rules): array
    {
        $hits = [];
        foreach ($rules as $rule) {
            if (! $rule->active || $rule->kind !== ComplianceRuleKinds::TARGETING) {
                continue;
            }
            foreach ((array) $rule->patterns as $pattern) {
                if (preg_match('/^age_min\s*<\s*(\d+)$/', trim((string) $pattern), $m) === 1) {
                    $ageMin = (int) ($targeting['age_min'] ?? 18);
                    if ($ageMin < (int) $m[1]) {
                        $hits[] = ['rule' => $rule, 'matched' => 'age_min='.$ageMin, 'excerpt' => 'Yaş aralığı: '.$ageMin.'–'.($targeting['age_max'] ?? '65+')];
                    }
                }
            }
        }

        return $hits;
    }

    private function excerpt(string $text, string $pattern): string
    {
        $needle = SeoText::fold($pattern);
        $words = preg_split('/\s+/u', $text) ?: [];
        $foldedWords = array_map(fn (string $w): string => SeoText::fold($w), $words);
        $first = explode(' ', $needle)[0] ?? '';
        $index = null;
        foreach ($foldedWords as $i => $word) {
            if ($first !== '' && str_contains($word, $first)) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return mb_substr($text, 0, 200);
        }
        $start = max(0, $index - 10);

        return ($start > 0 ? '… ' : '').implode(' ', array_slice($words, $start, 24)).($start + 24 < count($words) ? ' …' : '');
    }
}
