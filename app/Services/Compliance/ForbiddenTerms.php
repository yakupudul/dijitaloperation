<?php

namespace App\Services\Compliance;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Models\ServiceCategory;
use App\Services\ExternalWrites\ContentComplianceGate;
use Illuminate\Support\Collection;

/**
 * Yasaklı ifadeler (docs/product/CONTENT_IDEAS_BLUEPRINT.md §7) for the content AI work: the forbidden-phrase rules
 * of a brand (sector packs + sector rules + brand rules) or of a sector (from Sorgular, without a brand). "Engelle"
 * = high / medium severity (the text is not stored), "uyar" = low (marked in the preview). One source: the
 * compliance_rules table, the same rules the WordPress gate and the daily auditor use.
 */
final class ForbiddenTerms
{
    public const array SEVERITY_LABELS = ['high' => 'engelle', 'medium' => 'engelle', 'low' => 'uyar'];

    private const int PROMPT_LIMIT = 120;

    /** @param  Collection<int, ComplianceRule>  $rules */
    private function __construct(private readonly Collection $rules) {}

    public static function forBrand(?Brand $brand): self
    {
        return new self($brand !== null ? self::forbiddenOnly(app(SectorPackRegistry::class)->rulesForBrand($brand)) : collect());
    }

    public static function forSector(?int $sectorId): self
    {
        $code = $sectorId !== null ? ServiceCategory::query()->whereKey($sectorId)->value('code') : null;

        // Without a brand "{marka}" has nothing to stand for: those rules wait for the brand's own content work.
        return new self($code !== null ? self::forbiddenOnly(app(SectorPackRegistry::class)->rulesForSector((string) $code))
            ->reject(fn (ComplianceRule $rule): bool => SectorPackRegistry::hasBrandToken($rule))->values() : collect());
    }

    /** @return list<string> every forbidden phrase, for the AI pack */
    public function phrases(): array
    {
        return array_values(array_slice(array_unique($this->rules->flatMap(fn (ComplianceRule $r): array => array_map('strval', (array) $r->patterns))
            ->map(fn (string $p): string => trim($p))->filter()->all()), 0, self::PROMPT_LIMIT));
    }

    /** @return list<string> phrases of blocking ("engelle") rules found in the text */
    public function blocking(string $text): array
    {
        return $this->hits($text, true);
    }

    /**
     * The text without its blocking phrases ("En iyi pedodonti uzmanını seçerken…" → "Pedodonti uzmanını seçerken…"), so
     * plan inputs the writer copies (questions, outline) do not carry a forbidden phrase into the article.
     */
    public function scrub(string $text): string
    {
        $hits = $this->blocking($text);
        if ($hits === []) {
            return $text;
        }
        $capitalized = preg_match('/^\p{Lu}/u', $text) === 1;
        foreach ($hits as $phrase) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu', '', $text);
        }
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', $text));

        // A sentence keeps its capital; a lowercase search query stays lowercase.
        return $text === '' || ! $capitalized ? $text : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** @return list<string> phrases of "uyar" rules found in the text */
    public function warnings(string $text): array
    {
        return $this->hits($text, false);
    }

    /** @return list<string> */
    private function hits(string $text, bool $blocking): array
    {
        $rules = $this->rules->filter(fn (ComplianceRule $r): bool => in_array($r->severity, ['high', 'medium'], true) === $blocking);
        if ($rules->isEmpty() || trim($text) === '') {
            return [];
        }

        return array_values(array_unique(array_map(fn (array $hit): string => (string) $hit['matched'],
            (new ComplianceChecker)->checkText($text, $rules, ContentComplianceGate::SOURCE))));
    }

    /**
     * @param  Collection<int, ComplianceRule>  $rules
     * @return Collection<int, ComplianceRule>
     */
    private static function forbiddenOnly(Collection $rules): Collection
    {
        return $rules->filter(fn (ComplianceRule $r): bool => $r->kind === ComplianceRuleKinds::FORBIDDEN && $r->appliesTo(ContentComplianceGate::SOURCE))->values();
    }
}
