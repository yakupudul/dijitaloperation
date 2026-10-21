<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sistem borcu (yakup 2026-10-21, "hepsini yap"): tables of removed features that no code reads or writes any more
 * (search demand, prospect / sales radar, report delivery, agency brain, playbooks, DataForSEO snapshots, …). A table
 * is dropped only when it exists and is empty; one still holding rows is kept and named in the log.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'agency_leads',
        'ai_visibility_checks',
        'asset_renewals',
        'backlink_opportunities',
        'backlink_referring_domains',
        'backlink_snapshots',
        'brain_ad_group_clusters',
        'brain_cannibalizations',
        'brain_embeddings',
        'brain_legal_eligibility',
        'brain_meta_ads',
        'brain_methods',
        'brain_page_features',
        'brain_proposals',
        'brain_recommendations',
        'brain_success_snapshots',
        'brand_demand_queries',
        'brand_demand_query_assets',
        'brand_experience_evidence_links',
        'brand_experience_goals',
        'brand_experience_offerings',
        'brand_experience_revisions',
        'brand_experiences',
        'brand_intel_settings',
        'brand_query_portfolio_assets',
        'brand_query_portfolio_item_area',
        'brand_query_portfolio_item_service',
        'brand_query_portfolio_items',
        'business_outcome_definitions',
        'business_outcome_import_batches',
        'business_outcome_observation_revisions',
        'business_outcome_observations',
        'business_outcome_recheck_schedule_recipients',
        'business_outcome_recheck_schedules',
        'client_approvals',
        'competitor_site_snapshots',
        'content_articles',
        'content_calendar_items',
        'customer_interactions',
        'dataforseo_competitor_domain_snapshot',
        'dataforseo_keyword_site_snapshot',
        'dataforseo_ranked_keyword_snapshot',
        'dataforseo_serp_locations',
        'demand_page_snapshots',
        'demand_serp_checks',
        'ga4_landing_channel_daily',
        'google_ads_auction_insights',
        'inbox_item_states',
        'inbox_snoozes',
        'intelligence_evaluation_assertion_results',
        'intelligence_evaluation_baselines',
        'intelligence_evaluation_case_runs',
        'intelligence_evaluation_human_reviews',
        'intelligence_evaluation_judge_results',
        'intelligence_evaluation_runs',
        'invoices',
        'lead_outcomes',
        'library_cluster_operation_rows',
        'library_cluster_operations',
        'library_cluster_targets',
        'library_query_aliases',
        'library_query_clusters',
        'library_query_service_blocks',
        'map_grid_points',
        'map_grid_runs',
        'method_settings',
        'monthly_reports',
        'playbook_instructions',
        'playbook_references',
        'playbook_revision_asset_types',
        'playbook_revision_execution_scopes',
        'playbook_revision_services',
        'playbook_revisions',
        'prospect_activities',
        'prospect_audits',
        'prospect_discovery_candidates',
        'prospect_evidence',
        'prospect_report_artifacts',
        'prospect_report_share_grants',
        'prospect_report_snapshots',
        'prospect_research_runs',
        'prospect_sales_intelligence',
        'query_exclusion_exceptions',
        'query_exclusion_import_rows',
        'query_exclusion_matches',
        'query_exclusion_rules',
        'query_exclusion_runs',
        'query_ingest_states',
        'query_variants',
        'recurring_review_check_definitions',
        'recurring_review_run_item_task_links',
        'recurring_review_run_items',
        'recurring_review_schedules',
        'report_artifacts',
        'report_delivery_attempts',
        'report_delivery_schedule_recipients',
        'report_delivery_schedules',
        'report_share_grants',
        'report_share_sessions',
        'report_share_verification_challenges',
        'report_snapshots',
        'resource_query_batches',
        'resource_query_observations',
        'review_items',
        'review_profile_snapshots',
        'review_profiles',
        'sales_intent_activities',
        'sales_intent_radar_runs',
        'sales_intent_signals',
        'sales_radar_pages',
        'sales_radar_sources',
        'sales_search_profiles',
        'search_demand_ai_candidates',
        'search_demand_ai_runs',
        'search_demand_change_trackings',
        'search_demand_change_verification_runs',
        'search_demand_cluster_candidates',
        'search_demand_cluster_memberships',
        'search_demand_cluster_versions',
        'search_demand_clustering_runs',
        'search_demand_clusters',
        'search_demand_competitive_intelligence_runs',
        'search_demand_competitive_page_analyses',
        'search_demand_competitor_area',
        'search_demand_competitor_cluster',
        'search_demand_competitor_page_observations',
        'search_demand_competitor_page_run_items',
        'search_demand_competitor_queries',
        'search_demand_competitor_service',
        'search_demand_competitor_sources',
        'search_demand_competitor_urls',
        'search_demand_enrichment_run_items',
        'search_demand_enrichment_runs',
        'search_demand_expansion_candidates',
        'search_demand_improvement_proposals',
        'search_demand_improvement_runs',
        'search_demand_keyword_metric_snapshots',
        'search_demand_page_candidates',
        'search_demand_page_ownership_versions',
        'search_demand_page_ownerships',
        'search_demand_page_relevance_runs',
        'search_demand_serp_cluster_reviews',
        'search_demand_serp_results',
        'search_demand_serp_snapshots',
        'search_query_library_imports',
        'search_query_library_item_service',
        'search_query_library_items',
        'search_query_library_sectors',
        'search_query_library_source_records',
        'sector_learning_artifacts',
        'sector_learning_lineage_entries',
        'sector_learning_revisions',
        'service_commitment_marks',
        'service_commitments',
        'service_page_assignments',
        'time_entries',
        'topic_cluster_queries',
        'topic_map_builds',
        'website_url_audits',
        'website_url_verdicts',
    ];

    public function up(): void
    {
        $droppable = [];
        $kept = [];
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->exists()) {
                $kept[] = $table;

                continue;
            }
            $droppable[] = $table;
        }
        if ($kept !== []) {
            Log::info('Unused tables with rows kept: '.implode(', ', $kept));
        }

        // PostgreSQL: CASCADE removes only foreign-key constraints of other tables pointing here, never their rows.
        if (DB::getDriverName() === 'pgsql') {
            foreach ($droppable as $table) {
                DB::statement('DROP TABLE IF EXISTS '.DB::getQueryGrammar()->wrapTable($table).' CASCADE');
            }

            return;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($droppable as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the removed feature code no longer exists to use these tables.
    }
};
