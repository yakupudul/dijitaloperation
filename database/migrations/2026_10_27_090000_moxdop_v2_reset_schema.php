<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 0 (Temizlik).
 *
 * 1. Drops the tables of the modules removed from code (Hizmet Beyni, Arama Talebi, old advisors, SEO Görevleri,
 *    Komuta merkezi, Portföy sağlığı, İçerik takvimi / stüdyosu, Lead kutusu, Potansiyel müşteriler, Ajans işletmesi,
 *    WhatsApp, map grid, review intelligence, competitor watch, backlinks v1, external audit, customer health, AI
 *    visibility, annotations, KVKK tracking, renewals, monthly report, URL verdicts, topic map, BrandDemand hub, old
 *    query pipeline). `moxdop:reset` runs before deploy; data loss is intended.
 * 2. Renames analyst_decisions → suggestions (≥ 70 % overlap: brand, channel, fingerprint, title, why→reason,
 *    priority, evidence, action_type + action_params→action, status, snooze, baseline, outcome) and adds the v2
 *    columns (target, page, cluster, prompt version, applied / measured timestamps).
 * 3. Creates the v2 table families: pages · query_sources · queries · brand_queries · filter_terms · clusters ·
 *    cluster_queries · brand_cluster_pages · brand_memory · prompt_versions.
 * 4. brand_service_areas.physical_branch, brand_offerings.priority, ai_usage_records.prompt_version_id,
 *    external_write_actions.suggestion_id.
 */
return new class extends Migration
{
    /** Children before parents. */
    private const array DROP = [
        // content studio / topic map / brand demand hub
        'topic_cluster_queries', 'content_articles', 'content_ideas', 'topic_clusters', 'topic_map_builds',
        'demand_page_snapshots', 'demand_service_comparisons', 'demand_serp_checks', 'dataforseo_serp_locations',
        'brand_demand_query_assets', 'brand_demand_queries',
        // Hizmet Beyni
        'brain_legal_eligibility', 'brain_methods', 'brain_success_snapshots', 'brain_page_features', 'brain_recommendations',
        'brain_meta_ads', 'brain_ad_group_clusters', 'brain_cannibalizations', 'brain_embeddings', 'brain_proposals', 'method_settings',
        // Arama Talebi (search demand) + query library + old query pipeline
        'search_demand_change_verification_runs', 'search_demand_change_trackings',
        'search_demand_improvement_proposals', 'search_demand_improvement_runs',
        'search_demand_competitive_page_analyses', 'search_demand_competitive_intelligence_runs',
        'search_demand_competitor_page_observations', 'search_demand_competitor_page_run_items', 'competitor_site_snapshots',
        'search_demand_competitor_queries', 'search_demand_competitor_cluster', 'search_demand_competitor_area', 'search_demand_competitor_service',
        'search_demand_competitor_urls', 'search_demand_competitor_sources', 'search_demand_competitors',
        'search_demand_page_candidates', 'search_demand_page_relevance_runs', 'search_demand_page_ownership_versions', 'search_demand_page_ownerships',
        'search_demand_serp_cluster_reviews', 'search_demand_expansion_candidates', 'search_demand_provider_payloads', 'search_demand_keyword_metric_snapshots',
        'search_demand_serp_results', 'search_demand_serp_snapshots', 'search_demand_enrichment_run_items', 'search_demand_enrichment_runs',
        'search_demand_cluster_candidates', 'search_demand_clustering_runs', 'search_demand_cluster_versions', 'search_demand_cluster_memberships', 'search_demand_clusters',
        'search_demand_ai_candidates', 'search_demand_ai_runs',
        'brand_query_portfolio_assets', 'brand_query_portfolio_item_area', 'brand_query_portfolio_item_service', 'brand_query_portfolio_items',
        'query_exclusion_import_rows', 'query_exclusion_matches', 'query_exclusion_runs', 'query_exclusion_exceptions', 'query_exclusion_rules',
        'library_cluster_targets', 'library_cluster_operation_rows', 'library_cluster_operations', 'library_query_clusters',
        'resource_query_observations', 'resource_query_batches', 'library_query_aliases', 'library_query_service_blocks',
        'query_variants', 'query_ingest_states', 'asset_sectors',
        'search_query_library_sectors', 'search_query_library_item_service', 'search_query_library_source_records',
        'search_query_library_items', 'search_query_library_imports',
        // old advisors / SEO Görevleri / site fixes / URL verdicts
        'advisor_items', 'advisor_plans', 'seo_tasks', 'service_page_assignments', 'seo_plans', 'site_fix_items',
        'website_url_verdicts', 'website_url_audits',
        // sales: lead kutusu, potansiyel müşteriler, niyet radarı, dış denetim
        'agency_leads', 'prospect_audits', 'lead_outcomes',
        'sales_intent_activities', 'sales_intent_signals', 'sales_intent_radar_runs', 'sales_radar_pages', 'sales_radar_sources', 'sales_search_profiles',
        'prospect_report_share_grants', 'prospect_report_artifacts', 'prospect_report_snapshots',
        'prospect_activities', 'prospect_sales_intelligence', 'prospect_discovery_candidates', 'prospect_evidence', 'prospect_research_runs', 'prospects',
        // ajans işletmesi, müşteri sağlığı
        'service_commitment_marks', 'service_commitments', 'time_entries', 'customer_interactions', 'invoices', 'agency_invoices',
        'customer_health_history', 'customer_health',
        // reports v1 + monthly report + annotations + client approvals
        'report_delivery_attempts', 'report_deliveries', 'report_delivery_occurrences', 'report_delivery_schedule_recipients', 'report_delivery_schedules',
        'report_share_access_events', 'report_share_sessions', 'report_share_verification_challenges', 'report_share_grants',
        'report_artifacts', 'report_snapshots', 'monthly_reports', 'chart_annotations', 'client_approvals',
        // WhatsApp, renewals, content calendar, Komuta merkezi, AI visibility, map grid, backlinks v1, review intel
        'whatsapp_messages', 'whatsapp_conversations', 'whatsapp_webhook_receipts', 'whatsapp_signup_attempts',
        'asset_renewals', 'content_calendar_items', 'inbox_snoozes', 'inbox_item_states', 'ai_visibility_checks',
        'map_grid_points', 'map_grid_runs', 'brand_intel_settings',
        'backlink_opportunities', 'backlink_referring_domains', 'backlink_snapshots',
        'review_items', 'review_profile_snapshots', 'review_profiles',
    ];

    public function up(): void
    {
        $this->detachExternalWriteActions();
        $this->dropRemovedColumns();
        foreach (self::DROP as $table) {
            $this->dropTable($table);
        }
        $this->suggestions();
        $this->pages();
        $this->queries();
        $this->clusters();
        $this->brandMemory();
        $this->promptVersions();
        $this->brandColumns();
    }

    public function down(): void
    {
        // Faz 0 is a reset: the removed modules do not come back. Only the v2 additions are reversed.
        if (Schema::hasTable('suggestions') && Schema::hasColumn('suggestions', 'reason')) {
            Schema::table('suggestions', function (Blueprint $table): void {
                $table->dropForeign(['page_id']);
                $table->dropForeign(['cluster_id']);
                $table->dropForeign(['prompt_version_id']);
            });
            Schema::table('suggestions', function (Blueprint $table): void {
                $table->dropUnique('suggestions_brand_fingerprint_uq');
                $table->dropIndex('suggestions_brand_status_idx');
                $table->dropIndex('suggestions_brand_channel_idx');
                $table->dropColumn(['target_type', 'target_id', 'page_id', 'cluster_id', 'prompt_version_id', 'applied_at', 'measured_at']);
            });
            Schema::table('suggestions', fn (Blueprint $table) => $table->renameColumn('reason', 'why'));
            Schema::table('suggestions', fn (Blueprint $table) => $table->renameColumn('action', 'action_params'));
            Schema::table('suggestions', function (Blueprint $table): void {
                $table->unique(['brand_id', 'channel', 'fingerprint'], 'analyst_decisions_fingerprint_uq');
                $table->index(['brand_id', 'status', 'priority'], 'analyst_decisions_brand_status_idx');
            });
            Schema::rename('suggestions', 'analyst_decisions');
        }
        foreach (['brand_cluster_pages', 'cluster_queries', 'clusters', 'brand_queries', 'query_sources', 'queries', 'filter_terms', 'pages', 'brand_memory', 'prompt_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('ai_usage_records', 'prompt_version_id')) {
            Schema::table('ai_usage_records', fn (Blueprint $table) => $table->dropColumn('prompt_version_id'));
        }
        if (Schema::hasColumn('brand_service_areas', 'physical_branch')) {
            Schema::table('brand_service_areas', fn (Blueprint $table) => $table->dropColumn('physical_branch'));
        }
        if (Schema::hasColumn('brand_offerings', 'priority')) {
            Schema::table('brand_offerings', fn (Blueprint $table) => $table->dropColumn('priority'));
        }
        if (Schema::hasColumn('external_write_actions', 'suggestion_id')) {
            Schema::table('external_write_actions', function (Blueprint $table): void {
                $table->dropIndex('external_writes_suggestion_idx');
                $table->dropColumn('suggestion_id');
            });
        }
    }

    private function dropTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists "'.$table.'" cascade');

            return;
        }
        Schema::dropIfExists($table);
    }

    /** external_write_actions pointed at advisor_items / seo_tasks; the link is now the suggestion. */
    private function detachExternalWriteActions(): void
    {
        if (! Schema::hasTable('external_write_actions')) {
            return;
        }
        foreach (['advisor_item_id' => 'external_writes_item_idx', 'seo_task_id' => 'external_writes_task_idx'] as $column => $index) {
            if (! Schema::hasColumn('external_write_actions', $column)) {
                continue;
            }
            Schema::table('external_write_actions', function (Blueprint $table) use ($column, $index): void {
                $table->dropIndex($index);
                $table->dropForeign([$column]);
            });
            Schema::table('external_write_actions', fn (Blueprint $table) => $table->dropColumn($column));
        }
        if (! Schema::hasColumn('external_write_actions', 'suggestion_id')) {
            Schema::table('external_write_actions', function (Blueprint $table): void {
                $table->unsignedBigInteger('suggestion_id')->nullable()->after('brand_id');
                $table->index(['suggestion_id', 'id'], 'external_writes_suggestion_idx');
            });
        }
    }

    private function dropRemovedColumns(): void
    {
        if (Schema::hasColumn('customers', 'kvkk_dpa_signed_on')) {
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['kvkk_dpa_signed_on', 'kvkk_dpa_note', 'kvkk_health_data']));
        }
        if (Schema::hasColumn('agency_settings', 'whatsapp_retention_days')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('whatsapp_retention_days'));
        }
    }

    /** analyst_decisions → suggestions (rename + alter; the reset empties the rows before deploy). */
    private function suggestions(): void
    {
        if (Schema::hasTable('analyst_decisions') && ! Schema::hasTable('suggestions')) {
            Schema::rename('analyst_decisions', 'suggestions');
        }
        if (! Schema::hasTable('suggestions') || Schema::hasColumn('suggestions', 'reason')) {
            return;
        }
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->dropUnique('analyst_decisions_fingerprint_uq');
            $table->dropIndex('analyst_decisions_brand_status_idx');
        });
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->renameColumn('why', 'reason');
        });
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->renameColumn('action_params', 'action');
        });
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->string('target_type', 32)->nullable()->after('channel');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->unsignedBigInteger('page_id')->nullable()->after('target_id');
            $table->unsignedBigInteger('cluster_id')->nullable()->after('page_id');
            $table->unsignedBigInteger('prompt_version_id')->nullable()->after('cluster_id');
            $table->timestampTz('applied_at')->nullable()->after('outcome');
            $table->timestampTz('measured_at')->nullable()->after('applied_at');
            $table->unique(['brand_id', 'fingerprint'], 'suggestions_brand_fingerprint_uq');
            $table->index(['brand_id', 'status', 'priority'], 'suggestions_brand_status_idx');
            $table->index(['brand_id', 'channel', 'status'], 'suggestions_brand_channel_idx');
        });
    }

    private function pages(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('path', 2048);
            $table->string('category', 16)->default('diger'); // hizmet | blog | kurumsal | sss | lokasyon | diger
            $table->string('language', 8)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('meta_description', 1000)->nullable();
            $table->text('canonical')->nullable();
            $table->string('h1', 500)->nullable();
            $table->json('headings')->nullable();
            $table->longText('content_text')->nullable();
            $table->text('content_summary')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_post_type', 64)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->timestampTz('changed_at')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'url_hash'], 'pages_site_url_uq');
            $table->index(['website_asset_id', 'category'], 'pages_site_category_idx');
            $table->index(['website_asset_id', 'wp_post_id'], 'pages_site_wp_idx');
        });
    }

    private function queries(): void
    {
        Schema::create('queries', function (Blueprint $table): void {
            $table->id();
            $table->string('text', 500);
            $table->char('text_hash', 64)->unique('queries_text_hash_uq');
            $table->foreignId('sector_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->string('assignment', 8)->default('none'); // rule | ai | manual | none
            $table->boolean('locked')->default(false);
            $table->boolean('is_suggested')->default(false);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('volume')->nullable();
            $table->date('first_seen_on')->nullable();
            $table->date('last_seen_on')->nullable();
            $table->timestampsTz();

            $table->index(['sector_id', 'service_id'], 'queries_sector_service_idx');
            $table->index(['service_id', 'impressions'], 'queries_service_impressions_idx');
        });

        Schema::create('query_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('source', 16); // gsc | google_ads | gbp
            $table->string('raw_query', 500);
            $table->date('month');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('position', 8, 2)->nullable();
            $table->decimal('cost', 14, 4)->nullable();
            $table->decimal('conversions', 12, 2)->nullable();
            $table->foreignId('query_id')->nullable()->constrained('queries')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['external_resource_id', 'raw_query', 'month'], 'query_sources_resource_query_month_uq');
            $table->index(['query_id', 'month'], 'query_sources_query_month_idx');
            $table->index(['source', 'month'], 'query_sources_source_month_idx');
        });

        Schema::create('brand_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete();
            $table->foreignId('target_area_id')->nullable()->constrained('brand_service_areas')->nullOnDelete();
            $table->string('language', 8)->nullable();
            $table->text('url')->nullable();
            $table->unsignedBigInteger('clicks_28d')->default(0);
            $table->unsignedBigInteger('impressions_28d')->default(0);
            $table->decimal('position_28d', 8, 2)->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'query_id', 'target_area_id'], 'brand_queries_brand_query_area_uq');
            $table->index(['brand_id', 'impressions_28d'], 'brand_queries_brand_impressions_idx');
        });

        Schema::create('filter_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sector_id')->nullable()->constrained('service_categories')->cascadeOnDelete(); // null = global
            $table->string('term', 200);
            $table->string('source', 8)->default('manual'); // manual | ai
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['sector_id', 'term'], 'filter_terms_sector_term_uq');
        });
    }

    private function clusters(): void
    {
        Schema::create('clusters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sector_id')->constrained('service_categories')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('intent', 16)->default('commercial'); // commercial | local | informational | navigational | comparison
            $table->foreignId('main_query_id')->nullable()->constrained('queries')->nullOnDelete();
            $table->json('representative_query_ids')->nullable();
            $table->string('page_type', 16)->default('service'); // service | guide | faq | comparison | location | other
            $table->json('subtopics')->nullable();
            $table->text('reasoning')->nullable();
            $table->boolean('approved')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestampsTz();

            $table->index(['sector_id', 'service_id', 'approved'], 'clusters_sector_service_idx');
        });

        Schema::create('cluster_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete()->unique('cluster_queries_query_uq');
            $table->boolean('is_suggested')->default(false);
            $table->timestampsTz();
        });

        Schema::create('brand_cluster_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('state', 24)->default('insufficient_data'); // no_page | thin_coverage | weak_performance | possible_conflict | wrong_page | sufficient | insufficient_data
            $table->text('target_query')->nullable();
            $table->string('language', 8)->nullable();
            $table->boolean('locked')->default(false);
            $table->timestampsTz();

            $table->unique(['brand_id', 'cluster_id', 'website_asset_id', 'language'], 'brand_cluster_pages_uq');
            $table->index(['website_asset_id', 'state'], 'brand_cluster_pages_site_state_idx');
        });

        Schema::table('suggestions', function (Blueprint $table): void {
            $table->foreign('page_id')->references('id')->on('pages')->nullOnDelete();
            $table->foreign('cluster_id')->references('id')->on('clusters')->nullOnDelete();
        });
    }

    private function brandMemory(): void
    {
        Schema::create('brand_memory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('kind', 16); // profile | page | decision
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->text('summary')->nullable();
            $table->json('data')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['brand_id', 'kind', 'ref_type', 'ref_id'], 'brand_memory_ref_uq');
        });
    }

    private function promptVersions(): void
    {
        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 80);
            $table->unsignedInteger('version');
            $table->string('purpose', 500)->nullable();
            $table->longText('template');
            $table->json('variables')->nullable();
            $table->json('context_sources')->nullable();
            $table->json('output_schema')->nullable();
            $table->string('model', 190)->nullable();
            $table->boolean('is_current')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['operation', 'version'], 'prompt_versions_operation_version_uq');
            $table->index(['operation', 'is_current'], 'prompt_versions_current_idx');
        });
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->foreign('prompt_version_id')->references('id')->on('prompt_versions')->nullOnDelete();
        });
        if (Schema::hasTable('ai_usage_records') && ! Schema::hasColumn('ai_usage_records', 'prompt_version_id')) {
            Schema::table('ai_usage_records', function (Blueprint $table): void {
                $table->unsignedBigInteger('prompt_version_id')->nullable()->after('route_key');
            });
        }
    }

    private function brandColumns(): void
    {
        if (! Schema::hasColumn('brand_service_areas', 'physical_branch')) {
            Schema::table('brand_service_areas', function (Blueprint $table): void {
                $table->boolean('physical_branch')->default(false)->after('status');
            });
        }
        if (! Schema::hasColumn('brand_offerings', 'priority')) {
            Schema::table('brand_offerings', function (Blueprint $table): void {
                $table->string('priority', 12)->default('secondary')->after('status'); // main | secondary
            });
            DB::table('brand_offerings')->whereNotNull('priority_rank')->update(['priority' => 'main']);
        }
    }
};
