<?php

namespace App\Services\ContentStudio;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ContentDelivery\ContentComplianceGate;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * Sector rules for what the studio proposes (idea titles, focus keywords, outline lines, FAQ questions): a phrase a
 * forbidden rule matches is dropped or rephrased without the offending word (health: "implant fiyatları" → "implant
 * süreci"). Same `seo_brief` scope as the SEO plan briefs. Search queries themselves stay as evidence.
 */
final class BriefCompliance
{
    /** @param  Collection<int, ComplianceRule>  $rules */
    public function __construct(private readonly Collection $rules) {}

    public static function forBrand(?Brand $brand): self
    {
        return new self($brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($brand) : collect());
    }

    public function isCompliant(string $text): bool
    {
        return $this->forbidden($text) === [];
    }

    /** @return list<string> */
    public function forbidden(string $text): array
    {
        if ($this->rules->isEmpty() || trim($text) === '') {
            return [];
        }
        // A proposed title becomes an article title: both the brief and the AI-draft scopes apply.
        $checker = new ComplianceChecker;
        $hits = array_merge($checker->checkText($text, $this->rules, 'seo_brief'), $checker->checkText($text, $this->rules, ContentComplianceGate::SOURCE));

        return array_values(array_unique(array_map(static fn (array $hit): string => (string) $hit['matched'],
            array_filter($hits, static fn (array $hit): bool => $hit['rule']->kind === ComplianceRuleKinds::FORBIDDEN))));
    }

    /** @param  list<string>  $options */
    public function first(array $options, ?string $fallback = null): ?string
    {
        foreach ($options as $option) {
            if (trim($option) !== '' && $this->isCompliant($option)) {
                return $option;
            }
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    public function filter(array $texts): array
    {
        return array_values(array_filter($texts, fn (string $t): bool => trim($t) !== '' && $this->isCompliant($t)));
    }

    /** The query without its forbidden words; the service name when nothing sensible remains. */
    public function topic(string $query, string $serviceName): string
    {
        $patterns = $this->forbidden($query);
        if ($patterns === []) {
            return trim($query);
        }
        $stems = [];
        foreach ($patterns as $pattern) {
            foreach (explode(' ', SeoText::fold($pattern)) as $token) {
                if (mb_strlen($token) >= 3) {
                    $stems[] = $token;
                }
            }
        }
        $words = array_filter(preg_split('/\s+/u', trim($query)) ?: [], static function (string $word) use ($stems): bool {
            foreach ($stems as $stem) {
                if (SeoText::matchesPhrase($word, $stem)) {
                    return false;
                }
            }

            return true;
        });
        $topic = trim(implode(' ', $words));
        if ($topic === '' || ! $this->isCompliant($topic) || SeoText::tokens($topic) === []) {
            $topic = TopicText::lower($serviceName);
        }

        return $topic;
    }

    /**
     * Rules as the writer sees them.
     *
     * @return list<array{rule: string, avoid: list<string>, instruction: string}>
     */
    public function forPrompt(): array
    {
        return $this->rules->map(fn (ComplianceRule $r): array => ['rule' => (string) $r->label, 'avoid' => array_slice(array_map('strval', (array) $r->patterns), 0, 40), 'instruction' => (string) $r->message])
            ->values()->all();
    }
}
