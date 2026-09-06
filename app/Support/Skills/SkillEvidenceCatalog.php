<?php

namespace App\Support\Skills;

use App\Services\Evidence\EvidenceDefinitionRegistry;

/**
 * Allowed Skill Evidence requirement keys.
 *
 * Combines Prompt 38 Evidence Definition IDs with product-canonical observational
 * Evidence.type keys used by Website Diagnosis and provider collectors.
 */
final class SkillEvidenceCatalog
{
    /**
     * Observational / collector Evidence.type keys that Skills may require.
     *
     * @return list<string>
     */
    public static function observationalEvidenceTypes(): array
    {
        return [
            'page_html',
            'http_fetch',
            'robots',
            'sitemap',
            'search_console_performance',
            'ga4_events',
            'gbp_location_access',
            'technical_any',
            'gsc_any',
            'dataforseo_any',
        ];
    }

    public function __construct(
        private readonly EvidenceDefinitionRegistry $evidenceDefinitions,
    ) {}

    public function isKnown(string $key, string $kind): bool
    {
        if ($kind === SkillEvidenceRequirement::KIND_WORKFLOW_CONTEXT) {
            return in_array($key, [
                'standards', 'verified_brand_page', 'competitor_page_observations', 'search_demand_cluster',
                'services_and_markets', 'approved_competitive_analyses', 'page_relevance_signals',
                'website_page_candidates', 'gsc_query_page_observations', 'serp_brand_url_observations',
                'approved_improvement_proposal', 'applied_change_record', 'before_after_page_observations',
                'deterministic_technical_result', 'observational_metrics', 'source_queries', 'canonical_service',
                'brand_query_portfolio', 'existing_clusters', 'serp_validation', 'market_context', 'existing_queries',
            ], true);
        }
        if ($kind === SkillEvidenceRequirement::KIND_EVIDENCE_DEFINITION) {
            try {
                $this->evidenceDefinitions->get($key);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if ($kind === SkillEvidenceRequirement::KIND_EVIDENCE_TYPE) {
            if (in_array($key, self::observationalEvidenceTypes(), true)) {
                return true;
            }

            // Prefix wildcards used by eligibility (e.g. gsc_*).
            if (str_ends_with($key, '_*') || str_ends_with($key, '*')) {
                return true;
            }

            // Allow namespaced observational keys that modules already persist.
            return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $key);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function knownEvidenceDefinitionIds(): array
    {
        return array_map(
            static fn ($definition): string => $definition->id,
            $this->evidenceDefinitions->all()
        );
    }
}
