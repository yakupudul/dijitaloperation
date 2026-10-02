<?php

namespace App\Support\Skills;

/**
 * Global forbidden-claim policy inherited by every Skill Definition.
 *
 * Skills may ADD restrictions; they must not weaken these.
 */
final class SkillGlobalClaimPolicy
{
    /**
     * @return list<string>
     */
    public static function forbiddenClaims(): array
    {
        return [
            'Fabricate facts, metrics, or Evidence that were not supplied.',
            'Treat missing, unavailable, or unobserved data as zero or false.',
            'Present vendor estimates or external market intelligence as first-party measurement.',
            'Guarantee ranking, traffic, lead, revenue, or conversion outcomes.',
            'Assert undocumented search-engine ranking internals as fact.',
            'Conflate provider-native metrics (e.g. GSC clicks vs GA4 sessions; GSC impressions vs search volume; GSC average position vs exact SERP rank; DataForSEO ETV vs GA4 traffic; Ads conversions vs qualified leads without mapping; Meta action types collapsed into a generic Result).',
            'Invent Goal, Offering, Service Scope, Brand, competitor, or customer context.',
            'Emit magic composite SEO, GEO, content, E-E-A-T, authority, or AI-visibility scores as canonical MoxDOP metrics.',
            'Treat one sampled AI/GEO response as universal AI visibility truth.',
            'Equate WordPress configured state with observed rendered Website state without both provenances.',
            'Create Findings, Opportunities, Recommendations, Tasks, or provider writes autonomously.',
            'Claim unsupported causal SEO/GEO effects.',
        ];
    }

    /**
     * @param  list<string>  $skillSpecific
     * @return list<string>
     */
    public static function effectiveForbiddenClaims(array $skillSpecific): array
    {
        $merged = array_merge(self::forbiddenClaims(), $skillSpecific);

        return array_values(array_unique(array_filter(
            array_map(static fn (string $claim): string => trim($claim), $merged),
            static fn (string $claim): bool => $claim !== ''
        )));
    }
}
