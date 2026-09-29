<?php

namespace App\Services\ExternalWrites;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPackRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Compliance gate of AI-written articles (ADR-076): title, SEO title, meta description, excerpt, slug and body are
 * checked against the brand's sector-pack rules before anything goes to WordPress or into an export. High / medium
 * severity hits block delivery (same line as the Brain's brake); low ones are shown only. Stored nothing.
 */
final class ContentComplianceGate
{
    public const string SOURCE = 'ai_draft';

    public const array FIELD_LABELS = [
        'title' => 'Başlık',
        'meta_title' => 'SEO başlığı',
        'meta_description' => 'Meta açıklama',
        'focus_keyword' => 'Odak anahtar kelime',
        'excerpt' => 'Özet',
        'slug' => 'Adres (slug)',
        'body' => 'Metin',
    ];

    public function __construct(
        private readonly SectorPackRegistry $packs,
        private readonly ComplianceChecker $checker,
    ) {}

    /**
     * @return list<array{field: string, field_label: string, rule_key: string, label: string, matched: string, excerpt: string, message: string, severity: string, blocking: bool}>
     */
    public function violations(?Brand $brand, ArticleDraft $article): array
    {
        if ($brand === null) {
            return [];
        }
        $rules = $this->packs->rulesForBrand($brand);
        if ($rules->isEmpty()) {
            return [];
        }
        $fields = [
            'title' => $article->title,
            'meta_title' => $article->metaTitle,
            'meta_description' => $article->metaDescription,
            'focus_keyword' => $article->focusKeyword,
            'excerpt' => $article->excerpt,
            'slug' => str_replace('-', ' ', $article->slug),
            'body' => ComplianceAuditor::visibleText('<html><body>'.$article->html.'</body></html>'),
        ];
        $out = [];
        foreach ($fields as $field => $text) {
            foreach ($this->checker->checkText((string) $text, $rules, self::SOURCE) as $first) {
                foreach ($this->everyMatch((string) $text, $first) as $hit) {
                    $out[] = [
                        'field' => $field, 'field_label' => self::FIELD_LABELS[$field],
                        'rule_key' => (string) $hit['rule']->rule_key, 'label' => (string) $hit['rule']->label,
                        'matched' => $hit['matched'], 'excerpt' => mb_substr($hit['excerpt'], 0, 300),
                        'message' => (string) $hit['rule']->message, 'severity' => (string) $hit['rule']->severity,
                        'blocking' => in_array($hit['rule']->severity, ['high', 'medium'], true),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The checker stops at a rule's first matching phrase; the operator should see every offending phrase of that rule.
     *
     * @param  array{rule: ComplianceRule, matched: string, excerpt: string}  $hit
     * @return list<array{rule: ComplianceRule, matched: string, excerpt: string}>
     */
    private function everyMatch(string $text, array $hit): array
    {
        if ($hit['rule']->kind !== ComplianceRuleKinds::FORBIDDEN) {
            return [$hit];
        }
        $hits = [];
        foreach ((array) $hit['rule']->patterns as $pattern) {
            $single = $hit['rule']->replicate();
            $single->patterns = [$pattern];
            foreach ($this->checker->checkText($text, collect([$single]), self::SOURCE) as $match) {
                $hits[$match['matched']] = ['rule' => $hit['rule'], 'matched' => $match['matched'], 'excerpt' => $match['excerpt']];
            }
        }

        return $hits === [] ? [$hit] : array_values($hits);
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return list<array<string, mixed>>
     */
    public static function blocking(array $violations): array
    {
        return array_values(array_filter($violations, fn (array $v): bool => (bool) ($v['blocking'] ?? false)));
    }

    /** Refuses delivery while blocking violations remain (message in Turkish, with the offending phrases). */
    public function assertCompliant(?Brand $brand, ArticleDraft $article, string $what = 'Metin'): void
    {
        $blocking = self::blocking($this->violations($brand, $article));
        if ($blocking !== []) {
            throw ValidationException::withMessages(['compliance' => $what.' sektör uyum kurallarına takılıyor: '.self::summary($blocking).'. "Yeniden yaz (uyumlu)" ile yeniden yazdır ya da metni düzenle.']);
        }
    }

    /** "Başlık: «en iyi» (Üstünlük iddiası); Metin: «garanti» (Sonuç garantisi)" @param  list<array<string, mixed>>  $violations */
    public static function summary(array $violations): string
    {
        $parts = [];
        foreach ($violations as $v) {
            $parts[$v['field_label'].'|'.$v['matched']] = $v['field_label'].': «'.$v['matched'].'» ('.$v['label'].')';
        }

        return implode('; ', array_slice(array_values($parts), 0, 8)).(count($parts) > 8 ? ' …' : '');
    }

    /**
     * Compact form for AI re-prompts.
     *
     * @param  list<array<string, mixed>>  $violations
     * @return list<array{field: string, phrase: string, rule: string, instruction: string}>
     */
    public static function forPrompt(array $violations): array
    {
        return array_values(array_map(fn (array $v): array => ['field' => (string) $v['field'], 'phrase' => (string) $v['matched'], 'rule' => (string) $v['label'], 'instruction' => (string) $v['message']],
            self::blocking($violations)));
    }
}
