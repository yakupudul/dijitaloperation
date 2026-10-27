<?php

namespace App\Services\ExternalWrites;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoText;
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
     * Kural temizliği (yakup 2026-10-27: "kurala takılanı sistem düzeltsin"): the last step after the writer's own fix.
     * Every sentence of the body that holds a blocking phrase is dropped (a heading or list item left empty goes too);
     * in the title, SEO fields and excerpt only the phrase itself goes. Nothing is added, so nothing can be invented.
     *
     * @param  array<string, mixed>  $article  title, slug, meta_title, meta_description, excerpt, html
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, mixed>
     */
    public static function scrub(array $article, array $violations): array
    {
        $fields = ['title' => 'title', 'meta_title' => 'meta_title', 'meta_description' => 'meta_description', 'excerpt' => 'excerpt', 'slug' => 'title', 'body' => 'html'];
        foreach (self::blocking($violations) as $v) {
            $phrase = trim((string) ($v['matched'] ?? ''));
            $key = $fields[$v['field'] ?? ''] ?? null;
            if ($phrase === '' || $key === null || ! is_string($article[$key] ?? null)) {
                continue;
            }
            $article[$key] = match ($key) {
                'html' => self::dropSentences($article[$key], $phrase),
                'meta_description', 'excerpt' => self::dropTextSentences($article[$key], $phrase),
                default => self::dropPhrase($article[$key], $phrase),
            };
            if (($v['field'] ?? '') === 'slug' || $key === 'title') {
                $article['slug'] = SeoText::slugify((string) $article['title']);
            }
        }

        return $article;
    }

    /** The phrase removed from a one-line field, spaces and dangling punctuation tidied. */
    private static function dropPhrase(string $text, string $phrase): string
    {
        $out = (string) preg_replace('/'.preg_quote($phrase, '/').'/iu', ' ', $text);
        $out = (string) preg_replace(['/\s+([,.;:!?])/u', '/\s{2,}/u'], ['$1', ' '], $out);

        $out = trim($out, " \t\n,;:-–");

        return mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1);
    }

    /** Plain text (meta description, excerpt): the sentences that hold the phrase go. */
    private static function dropTextSentences(string $text, string $phrase): string
    {
        $parts = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [$text];

        return implode(' ', array_filter($parts, fn (string $p): bool => mb_stripos($p, $phrase) === false));
    }

    /** Every sentence of the HTML's text that holds the phrase is dropped; elements left empty go too. */
    private static function dropSentences(string $html, string $phrase): string
    {
        $has = fn (string $text): bool => mb_stripos(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $phrase) !== false;
        // A phrase inside or across inline tags (<strong>en iyi</strong> klinik) is not one sentence of plain text: its block goes.
        $html = (string) preg_replace_callback('/<(p|li|h[2-6])\b[^>]*>(.*?)<\/\1>/isu', function (array $m) use ($has): string {
            if (! $has(strip_tags($m[2])) || ! str_contains($m[2], '<')) {
                return $m[0];
            }
            preg_match_all('/<(strong|em|b|i|a|span)\b[^>]*>(.*?)<\/\1>/isu', $m[2], $inline);
            $inInline = array_filter($inline[2], fn (string $t): bool => $has(strip_tags($t))) !== [];
            $inPlain = array_filter(preg_split('/<[^>]+>/u', $m[2]) ?: [], $has) !== [];

            return $inInline || ! $inPlain ? '' : $m[0];
        }, $html);
        $out = (string) preg_replace_callback('/>([^<]+)</u', function (array $m) use ($phrase): string {
            if (mb_stripos(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $phrase) === false) {
                return $m[0];
            }
            $parts = preg_split('/(?<=[.!?…])\s+/u', $m[1]) ?: [$m[1]];
            $kept = array_filter($parts, fn (string $p): bool => mb_stripos(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $phrase) === false);

            return '>'.implode(' ', $kept).'<';
        }, '<x>'.$html.'</x>');
        $out = substr($out, 3, -4);
        for ($i = 0; $i < 3; $i++) {
            $out = (string) preg_replace('/<(p|li|h[2-6]|strong|em|b|i|span|a|ul|ol)\b[^>]*>\s*<\/\1>/iu', '', $out);
        }

        return trim($out);
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
