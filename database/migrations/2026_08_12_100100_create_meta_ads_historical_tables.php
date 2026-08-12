<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads historical store — MySQL 8 + SQLite compatible.
 *
 * Anchored on Integration + External Resource (Meta Ad Account), not Digital Asset —
 * history can be imported before an operator binds the account to a Brand/Digital Asset.
 * Provider external ID is the canonical entity identity (never a MoxDOP-generated key
 * for join purposes).
 *
 * Non-additive metrics (reach, frequency) are never summed/averaged across days in
 * `meta_ads_daily_facts` — see `meta_ads_period_aggregates` for exact-period provider
 * values. Missing rows/columns mean "not collected", never a fabricated zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('entity_type'); // account|campaign|adset|ad|creative
            $table->string('provider_external_id');
            $table->string('parent_provider_external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('objective')->nullable();
            $table->string('optimization_goal')->nullable();
            $table->string('destination_type')->nullable();
            $table->string('creative_provider_id')->nullable();
            $table->string('currency')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(
                ['core_external_resource_id', 'entity_type', 'provider_external_id'],
                'meta_ads_entities_unique_entity',
            );
            $table->index(['core_external_resource_id', 'entity_type'], 'meta_ads_entities_resource_type_idx');
            $table->index('parent_provider_external_id', 'meta_ads_entities_parent_idx');
            $table->index('core_integration_id', 'meta_ads_entities_integration_idx');
        });

        Schema::create('meta_ads_daily_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('entity_type'); // account|campaign|adset|ad
            $table->string('provider_external_id');
            $table->string('parent_provider_external_id')->nullable();
            $table->date('date');
            $table->decimal('spend', 16, 4)->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable(); // all clicks
            $table->unsignedBigInteger('link_clicks')->nullable();
            $table->unsignedBigInteger('outbound_clicks')->nullable();
            // DAILY provider value for that day only — NEVER sum across days for a range.
            $table->unsignedBigInteger('reach')->nullable();
            // DAILY only — NEVER average for a range claim.
            $table->decimal('frequency', 12, 6)->nullable();
            $table->decimal('cpc', 16, 6)->nullable(); // provider-returned provenance
            $table->decimal('cpm', 16, 6)->nullable();
            $table->decimal('ctr', 16, 8)->nullable();
            $table->decimal('link_ctr', 16, 8)->nullable();
            $table->string('currency')->nullable();
            $table->string('attribution_setting')->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->unique(
                ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date'],
                'meta_ads_daily_facts_unique_fact',
            );
            $table->index(['core_external_resource_id', 'entity_type', 'date'], 'meta_ads_daily_facts_resource_type_date_idx');
            $table->index(['core_external_resource_id', 'date'], 'meta_ads_daily_facts_resource_date_idx');
            $table->index(['core_integration_id', 'date'], 'meta_ads_daily_facts_integration_date_idx');
        });

        Schema::create('meta_ads_daily_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('provider_external_id');
            $table->date('date');
            $table->string('raw_action_type');
            $table->string('normalized_family')->nullable();
            $table->decimal('value', 16, 4)->nullable(); // count
            $table->decimal('action_value', 16, 4)->nullable(); // monetary if present
            // SQLite treats NULL as distinct in unique indexes — use '' (not null) so the
            // uniqueness constraint below is actually idempotent for "no window" rows.
            $table->string('attribution_window')->default('');
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->unique(
                ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date', 'raw_action_type', 'attribution_window'],
                'meta_ads_daily_actions_unique_action',
            );
            $table->index(['core_external_resource_id', 'entity_type', 'date'], 'meta_ads_daily_actions_resource_type_date_idx');
            $table->index(['core_external_resource_id', 'date'], 'meta_ads_daily_actions_resource_date_idx');
            $table->index(['core_integration_id', 'date'], 'meta_ads_daily_actions_integration_date_idx');
        });

        // Exact non-additive / provider period cache (e.g. reach, frequency for a range).
        Schema::create('meta_ads_period_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('provider_external_id');
            $table->date('date_from');
            $table->date('date_to');
            $table->string('attribution_context')->default('unified');
            $table->string('metric_key'); // e.g. reach, frequency
            $table->decimal('metric_value', 20, 6)->nullable();
            $table->string('status'); // ready|pending|unavailable|failed
            $table->json('provenance')->nullable();
            $table->foreignId('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date_from', 'date_to', 'attribution_context', 'metric_key'],
                'meta_ads_period_aggregates_unique_metric',
            );
        });

        Schema::create('meta_ads_history_coverage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('data_layer'); // entities|daily_facts|daily_actions|period_aggregates
            $table->string('granularity')->default('day');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status'); // not_imported|importing|partial|complete|outside_provider
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->date('earliest_provider_date')->nullable();
            $table->date('latest_provider_date')->nullable();
            $table->json('gaps')->nullable();
            $table->foreignId('import_run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(
                ['core_external_resource_id', 'data_layer', 'granularity'],
                'meta_ads_history_coverage_unique_layer',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_history_coverage');
        Schema::dropIfExists('meta_ads_period_aggregates');
        Schema::dropIfExists('meta_ads_daily_actions');
        Schema::dropIfExists('meta_ads_daily_facts');
        Schema::dropIfExists('meta_ads_entities');
    }
};
