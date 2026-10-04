<?php

namespace App\Services\Compliance;

use App\Ai\Agents\Site\ForbiddenTermsAgent;
use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Queries\QueryNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteAi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Sorgular › Yasaklı ifadeler (docs/product/CONTENT_IDEAS_BLUEPRINT.md §7): a sector's forbidden phrases are its
 * sector pack rules plus its own rules (pack_id "sector:{code}"), one source with the compliance gate and auditor.
 * Each own rule is one phrase with reason, severity (high = engelle, low = uyar) and origin (operator / ai). "AI ile
 * öner" proposes candidates (`compliance.forbidden_terms`) the operator approves one by one. Brand-only phrases
 * (Marka › Ayarlar) live in one rule per brand (pack_id "brand:{id}").
 */
final class ForbiddenTermsLibrary
{
    /** Sources of the content the AI writes (article drafts and content briefs). */
    public const array AI_CONTENT_SOURCES = ['ai_draft', 'seo_brief'];

    public const string SEVERITY_BLOCK = 'high';

    public const string SEVERITY_WARN = 'low';

    public function __construct(
        private readonly SiteAi $ai,
        private readonly SectorPackRegistry $packs,
    ) {}

    public static function cacheKey(int $sectorId): string
    {
        return 'forbidden-terms:'.$sectorId;
    }

    /** @return Collection<int, ComplianceRule> forbidden rules of the sector (active and inactive) */
    public function rules(ServiceCategory $sector): Collection
    {
        return $this->packs->rulesForSector((string) $sector->code, activeOnly: false)
            ->filter(fn (ComplianceRule $r): bool => $r->kind === ComplianceRuleKinds::FORBIDDEN)->values();
    }

    public function add(ServiceCategory $sector, string $phrase, string $reason, string $severity, string $origin = 'operator'): ComplianceRule
    {
        $phrase = QueryNormalizer::lower(trim($phrase));
        if (mb_strlen($phrase) < 2 || mb_strlen($phrase) > 120) {
            throw ValidationException::withMessages(['forbiddenPhrase' => 'İfade 2–120 karakter olmalı.']);
        }
        if ($this->known($sector, $phrase)) {
            throw ValidationException::withMessages(['forbiddenPhrase' => 'Bu ifade bu sektörde zaten var.']);
        }

        return ComplianceRule::query()->create([
            'pack_id' => SectorPackRegistry::SECTOR_PREFIX.$sector->code, 'rule_key' => 'term-'.substr(hash('sha256', SeoText::fold($phrase)), 0, 16),
            'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => mb_substr($phrase, 0, 160), 'patterns' => [$phrase],
            'message' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 1000) : 'Bu ifade sektörde kullanılmaz.',
            'severity' => $severity === self::SEVERITY_WARN ? self::SEVERITY_WARN : self::SEVERITY_BLOCK,
            'applies_to' => self::appliesTo($phrase), 'active' => true, 'origin' => $origin === 'ai' ? 'ai' : 'operator',
        ]);
    }

    /**
     * A "{marka}" phrase is for the content the AI writes only: the brand's own pages, profile and ads name the brand
     * by nature, so the daily auditor must not flag them.
     *
     * @return list<string>
     */
    public static function appliesTo(string $phrase): array
    {
        return str_contains($phrase, SectorPackRegistry::BRAND_TOKEN) ? self::AI_CONTENT_SOURCES : ComplianceRuleKinds::TEXT_SOURCES;
    }

    /** Brand-only phrases: one rule per brand, replaced as a whole. @param  list<string>  $phrases */
    public function saveBrand(Brand $brand, array $phrases): void
    {
        $phrases = array_values(array_unique(array_filter(array_map(fn (string $p): string => QueryNormalizer::lower(trim($p)), $phrases), fn (string $p): bool => $p !== '')));
        $key = ['pack_id' => SectorPackRegistry::BRAND_PREFIX.$brand->id, 'rule_key' => 'brand-terms'];
        if ($phrases === []) {
            ComplianceRule::query()->where($key)->delete();

            return;
        }
        ComplianceRule::query()->updateOrCreate($key, ['kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Markaya özel yasaklı ifadeler', 'patterns' => array_slice($phrases, 0, 200),
            'message' => 'Bu marka için kullanılmaz.', 'severity' => self::SEVERITY_BLOCK, 'applies_to' => ComplianceRuleKinds::TEXT_SOURCES, 'active' => true, 'origin' => 'operator']);
    }

    /** @return list<string> */
    public static function brandPhrases(Brand $brand): array
    {
        return array_values((array) ComplianceRule::query()->where('pack_id', SectorPackRegistry::BRAND_PREFIX.$brand->id)->where('rule_key', 'brand-terms')->value('patterns'));
    }

    /**
     * "AI ile öner": candidates stored in the cache for the operator (never written as rules here).
     *
     * @return array{status: string, items: list<array{phrase: string, reason: string, severity: string}>}
     */
    public function suggest(ServiceCategory $sector): array
    {
        $existing = $this->rules($sector)->flatMap(fn (ComplianceRule $r): array => array_map('strval', (array) $r->patterns))->values()->all();
        $result = $this->ai->run(new ForbiddenTermsAgent, [
            'sector' => (string) $sector->name,
            'services' => ServiceCatalogItem::query()->with('primaryName')->where('sector', $sector->code)->where('status', 'active')->limit(80)->get()
                ->map(fn (ServiceCatalogItem $s): string => (string) $s->primaryName?->raw_label)->filter()->values()->all(),
            'existing' => array_slice($existing, 0, 200),
        ], 180);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'items' => []];
        }
        $seen = array_flip(array_map(fn (string $p): string => SeoText::fold($p), $existing));
        $items = [];
        foreach (array_slice((array) ($result['data']['terms'] ?? []), 0, 25) as $row) {
            $phrase = is_array($row) ? QueryNormalizer::lower(trim((string) ($row['phrase'] ?? ''))) : '';
            $folded = SeoText::fold($phrase);
            if (mb_strlen($phrase) < 3 || mb_strlen($phrase) > 120 || isset($seen[$folded])) {
                continue;
            }
            $seen[$folded] = true;
            $items[] = ['phrase' => $phrase, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 300),
                'severity' => ($row['severity'] ?? 'block') === 'warn' ? self::SEVERITY_WARN : self::SEVERITY_BLOCK];
        }

        return ['status' => 'ready', 'items' => $items];
    }

    private function known(ServiceCategory $sector, string $phrase): bool
    {
        $folded = SeoText::fold($phrase);

        return $this->rules($sector)->contains(fn (ComplianceRule $r): bool => in_array($folded, array_map(fn ($p): string => SeoText::fold((string) $p), (array) $r->patterns), true));
    }
}
