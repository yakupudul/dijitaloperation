<?php

namespace MoxDop\Website\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * GA4 Measurement Analyst (product definition).
 *
 * Registered under the Website module while real GA4 collection remains transitional /
 * Website-scoped. Status is designed — no live GA4 AI execution pipeline claimed.
 */
final class Ga4MeasurementAnalyst
{
    public const string SLUG = AgentProfileKeys::GA4_MEASUREMENT_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'GA4 Measurement Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'ga4-measurement-quality',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'website',
            purpose: 'Interpret bounded Google Analytics Evidence as Measurement & Behavior Evidence, reasoning in Business Actions rather than raw event names alone.',
            status: 'designed',
            aiRouteKey: AiRouteKeys::GA4_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_ga4_digital_asset',
                'related_website_digital_asset',
                'relevant_complete_ga4_runs',
                'relevant_active_findings',
                'supporting_normalized_evidence',
                'assigned_eligible_skills',
            ],
            allowedOperations: [
                'analyze_bounded_context',
                'draft_ai_guidance',
                'cite_finding_and_evidence_ids',
            ],
            forbiddenOperations: [
                'access_credentials',
                'read_unrelated_customers_brands_assets',
                'arbitrary_database_access',
                'external_platform_writes',
                'ga4_mutations',
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
                'session_replay_or_pii',
            ],
            outputContract: 'Structured GA4 AI Guidance distinguishing Business Actions from raw events, with measurement quality observations and explicit uncertainty.',
            successCriteria: [
                'Raw GA4 events are never treated as Business Outcomes without mapping Evidence.',
                'Missing data is labeled Unavailable / Not collected — never invent zeros.',
                'No universal Measurement Score is invented.',
                'Outputs remain advisory; no provider writes.',
            ],
        );
    }
}
