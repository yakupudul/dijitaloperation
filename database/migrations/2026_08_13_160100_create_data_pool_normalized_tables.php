<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 10 normalized data-pool tables.
 * PostgreSQL: RANGE monthly partitions for high-volume daily facts.
 * SQLite/MySQL: equivalent non-partitioned tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // ga4_property_metadata | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('ga4_property_metadata')) {
            Schema::create('ga4_property_metadata', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('property_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'property_id'], 'ga4_property_metadata_nk_unique');
                $table->index(['digital_asset_id'], 'ga4_property_metadata_asset_idx');
            });
        }

        // ga4_property_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('ga4_property_daily')) {
            Schema::create('ga4_property_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('property_id');
                $table->date('reporting_date');
                $table->bigInteger('sessions')->default(0);
                $table->bigInteger('engagedSessions')->default(0);
                $table->bigInteger('screenPageViews')->default(0);
                $table->decimal('userEngagementDuration', 20, 6)->nullable();
                $table->bigInteger('totalUsers')->default(0);
                $table->bigInteger('activeUsers')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'property_id', 'reporting_date'], 'ga4_property_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'ga4_property_daily_asset_date_idx');
            });
        }

        // ga4_acquisition_channel_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('ga4_acquisition_channel_daily')) {
            Schema::create('ga4_acquisition_channel_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('property_id');
                $table->date('reporting_date');
                $table->text('sessionDefaultChannelGroup');
                $table->bigInteger('sessions')->default(0);
                $table->bigInteger('engagedSessions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'sessionDefaultChannelGroup'], 'ga4_acquisition_channel_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'ga4_acquisition_channel_daily_asset_date_idx');
            });
        }

        // ga4_source_medium_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_source_medium_daily')) {
                DB::statement('CREATE TABLE ga4_source_medium_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, sessionSource text NOT NULL, sessionMedium text NOT NULL, sessions bigint NOT NULL DEFAULT 0, engagedSessions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, sessionSource, sessionMedium)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_source_medium_daily_asset_date_idx ON ga4_source_medium_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_source_medium_daily')) {
                Schema::create('ga4_source_medium_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('sessionSource');
                    $table->text('sessionMedium');
                    $table->bigInteger('sessions')->default(0);
                    $table->bigInteger('engagedSessions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'sessionSource', 'sessionMedium'], 'ga4_source_medium_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_source_medium_daily_asset_date_idx');
                });
            }
        }

        // ga4_campaign_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_campaign_daily')) {
                DB::statement('CREATE TABLE ga4_campaign_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, sessionCampaignName text NOT NULL, sessions bigint NOT NULL DEFAULT 0, engagedSessions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, sessionCampaignName)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_campaign_daily_asset_date_idx ON ga4_campaign_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_campaign_daily')) {
                Schema::create('ga4_campaign_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('sessionCampaignName');
                    $table->bigInteger('sessions')->default(0);
                    $table->bigInteger('engagedSessions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'sessionCampaignName'], 'ga4_campaign_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_campaign_daily_asset_date_idx');
                });
            }
        }

        // ga4_landing_page_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_landing_page_daily')) {
                DB::statement('CREATE TABLE ga4_landing_page_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, landingPage text NOT NULL, sessions bigint NOT NULL DEFAULT 0, engagedSessions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, landingPage)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_landing_page_daily_asset_date_idx ON ga4_landing_page_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_landing_page_daily')) {
                Schema::create('ga4_landing_page_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('landingPage');
                    $table->bigInteger('sessions')->default(0);
                    $table->bigInteger('engagedSessions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'landingPage'], 'ga4_landing_page_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_landing_page_daily_asset_date_idx');
                });
            }
        }

        // ga4_event_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_event_daily')) {
                DB::statement('CREATE TABLE ga4_event_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, eventName text NOT NULL, eventCount bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, eventName)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_event_daily_asset_date_idx ON ga4_event_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_event_daily')) {
                Schema::create('ga4_event_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('eventName');
                    $table->bigInteger('eventCount')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'eventName'], 'ga4_event_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_event_daily_asset_date_idx');
                });
            }
        }

        // ga4_event_channel_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_event_channel_daily')) {
                DB::statement('CREATE TABLE ga4_event_channel_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, eventName text NOT NULL, sessionDefaultChannelGroup text NOT NULL, eventCount bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, eventName, sessionDefaultChannelGroup)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_event_channel_daily_asset_date_idx ON ga4_event_channel_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_event_channel_daily')) {
                Schema::create('ga4_event_channel_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('eventName');
                    $table->text('sessionDefaultChannelGroup');
                    $table->bigInteger('eventCount')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'eventName', 'sessionDefaultChannelGroup'], 'ga4_event_channel_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_event_channel_daily_asset_date_idx');
                });
            }
        }

        // ga4_event_campaign_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_event_campaign_daily')) {
                DB::statement('CREATE TABLE ga4_event_campaign_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, eventName text NOT NULL, sessionCampaignName text NOT NULL, eventCount bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, eventName, sessionCampaignName)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_event_campaign_daily_asset_date_idx ON ga4_event_campaign_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_event_campaign_daily')) {
                Schema::create('ga4_event_campaign_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('eventName');
                    $table->text('sessionCampaignName');
                    $table->bigInteger('eventCount')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'eventName', 'sessionCampaignName'], 'ga4_event_campaign_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_event_campaign_daily_asset_date_idx');
                });
            }
        }

        // ga4_event_landing_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('ga4_event_landing_daily')) {
                DB::statement('CREATE TABLE ga4_event_landing_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, property_id text NOT NULL, reporting_date date NOT NULL, eventName text NOT NULL, landingPage text NOT NULL, eventCount bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, property_id, reporting_date, eventName, landingPage)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS ga4_event_landing_daily_asset_date_idx ON ga4_event_landing_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('ga4_event_landing_daily')) {
                Schema::create('ga4_event_landing_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('property_id');
                    $table->date('reporting_date');
                    $table->text('eventName');
                    $table->text('landingPage');
                    $table->bigInteger('eventCount')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'eventName', 'landingPage'], 'ga4_event_landing_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'ga4_event_landing_daily_asset_date_idx');
                });
            }
        }

        // ga4_device_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('ga4_device_daily')) {
            Schema::create('ga4_device_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('property_id');
                $table->date('reporting_date');
                $table->text('deviceCategory');
                $table->bigInteger('sessions')->default(0);
                $table->bigInteger('engagedSessions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'property_id', 'reporting_date', 'deviceCategory'], 'ga4_device_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'ga4_device_daily_asset_date_idx');
            });
        }

        // gsc_property_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('gsc_property_daily')) {
            Schema::create('gsc_property_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->date('reporting_date');
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'reporting_date'], 'gsc_property_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'gsc_property_daily_asset_date_idx');
            });
        }

        // gsc_query_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('gsc_query_daily')) {
                DB::statement('CREATE TABLE gsc_query_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, site_url text NOT NULL, reporting_date date NOT NULL, query text NOT NULL, clicks bigint NOT NULL DEFAULT 0, impressions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, site_url, reporting_date, query)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS gsc_query_daily_asset_date_idx ON gsc_query_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('gsc_query_daily')) {
                Schema::create('gsc_query_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('site_url');
                    $table->date('reporting_date');
                    $table->text('query');
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('impressions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'query'], 'gsc_query_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'gsc_query_daily_asset_date_idx');
                });
            }
        }

        // gsc_page_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('gsc_page_daily')) {
                DB::statement('CREATE TABLE gsc_page_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, site_url text NOT NULL, reporting_date date NOT NULL, page text NOT NULL, clicks bigint NOT NULL DEFAULT 0, impressions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, site_url, reporting_date, page)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS gsc_page_daily_asset_date_idx ON gsc_page_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('gsc_page_daily')) {
                Schema::create('gsc_page_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('site_url');
                    $table->date('reporting_date');
                    $table->text('page');
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('impressions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'page'], 'gsc_page_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'gsc_page_daily_asset_date_idx');
                });
            }
        }

        // gsc_query_page_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('gsc_query_page_daily')) {
                DB::statement('CREATE TABLE gsc_query_page_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, site_url text NOT NULL, reporting_date date NOT NULL, query text NOT NULL, page text NOT NULL, clicks bigint NOT NULL DEFAULT 0, impressions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, site_url, reporting_date, query, page)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS gsc_query_page_daily_asset_date_idx ON gsc_query_page_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('gsc_query_page_daily')) {
                Schema::create('gsc_query_page_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('site_url');
                    $table->date('reporting_date');
                    $table->text('query');
                    $table->text('page');
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('impressions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'query', 'page'], 'gsc_query_page_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'gsc_query_page_daily_asset_date_idx');
                });
            }
        }

        // gsc_country_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('gsc_country_daily')) {
            Schema::create('gsc_country_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->date('reporting_date');
                $table->text('country');
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'country'], 'gsc_country_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'gsc_country_daily_asset_date_idx');
            });
        }

        // gsc_device_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('gsc_device_daily')) {
            Schema::create('gsc_device_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->date('reporting_date');
                $table->text('device');
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'device'], 'gsc_device_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'gsc_device_daily_asset_date_idx');
            });
        }

        // gsc_search_appearance_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('gsc_search_appearance_daily')) {
            Schema::create('gsc_search_appearance_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->date('reporting_date');
                $table->text('searchAppearance');
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'reporting_date', 'searchAppearance'], 'gsc_search_appearance_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'gsc_search_appearance_daily_asset_date_idx');
            });
        }

        // gsc_url_inspection_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('gsc_url_inspection_snapshot')) {
            Schema::create('gsc_url_inspection_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->text('page');
                $table->timestampTz('inspected_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'page', 'inspected_at'], 'gsc_url_inspection_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'gsc_url_inspection_snapshot_asset_idx');
            });
        }

        // gsc_sitemap_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('gsc_sitemap_snapshot')) {
            Schema::create('gsc_sitemap_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('site_url');
                $table->text('sitemap_path');
                $table->timestampTz('retrieved_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'site_url', 'sitemap_path', 'retrieved_at'], 'gsc_sitemap_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'gsc_sitemap_snapshot_asset_idx');
            });
        }

        // google_ads_account_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_account_snapshot')) {
            Schema::create('google_ads_account_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id'], 'google_ads_account_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_account_snapshot_asset_idx');
            });
        }

        // google_ads_account_daily | UPSERT_DAILY_FACT | NONE
        if (! Schema::hasTable('google_ads_account_daily')) {
            Schema::create('google_ads_account_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->date('reporting_date');
                $table->bigInteger('impressions')->default(0);
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('cost_micros');
                $table->bigInteger('conversions')->default(0);
                $table->decimal('cost_amount', 20, 6);
                $table->char('currency', 3);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'reporting_date'], 'google_ads_account_daily_nk_unique');
                $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_account_daily_asset_date_idx');
            });
        }

        // google_ads_campaign_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_campaign_snapshot')) {
            Schema::create('google_ads_campaign_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('campaign_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'campaign_id'], 'google_ads_campaign_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_campaign_snapshot_asset_idx');
            });
        }

        // google_ads_campaign_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('google_ads_campaign_daily')) {
                DB::statement('CREATE TABLE google_ads_campaign_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, customer_id text NOT NULL, reporting_date date NOT NULL, campaign_id text NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, cost_micros bigint NOT NULL, conversions bigint NOT NULL DEFAULT 0, search_impression_share numeric(20,6) NULL, cost_amount numeric(20,6) NOT NULL, currency char(3) NOT NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, customer_id, reporting_date, campaign_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS google_ads_campaign_daily_asset_date_idx ON google_ads_campaign_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('google_ads_campaign_daily')) {
                Schema::create('google_ads_campaign_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('customer_id');
                    $table->date('reporting_date');
                    $table->text('campaign_id');
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('cost_micros');
                    $table->bigInteger('conversions')->default(0);
                    $table->decimal('search_impression_share', 20, 6)->nullable();
                    $table->decimal('cost_amount', 20, 6);
                    $table->char('currency', 3);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'customer_id', 'reporting_date', 'campaign_id'], 'google_ads_campaign_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_campaign_daily_asset_date_idx');
                });
            }
        }

        // google_ads_ad_group_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_ad_group_snapshot')) {
            Schema::create('google_ads_ad_group_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('ad_group_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'ad_group_id'], 'google_ads_ad_group_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_ad_group_snapshot_asset_idx');
            });
        }

        // google_ads_ad_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_ad_snapshot')) {
            Schema::create('google_ads_ad_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('ad_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'ad_id'], 'google_ads_ad_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_ad_snapshot_asset_idx');
            });
        }

        // google_ads_keyword_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_keyword_snapshot')) {
            Schema::create('google_ads_keyword_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('criterion_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'criterion_id'], 'google_ads_keyword_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_keyword_snapshot_asset_idx');
            });
        }

        // google_ads_keyword_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('google_ads_keyword_daily')) {
                DB::statement('CREATE TABLE google_ads_keyword_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, customer_id text NOT NULL, reporting_date date NOT NULL, criterion_id text NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, cost_micros bigint NOT NULL, conversions bigint NOT NULL DEFAULT 0, cost_amount numeric(20,6) NOT NULL, currency char(3) NOT NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, customer_id, reporting_date, criterion_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS google_ads_keyword_daily_asset_date_idx ON google_ads_keyword_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('google_ads_keyword_daily')) {
                Schema::create('google_ads_keyword_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('customer_id');
                    $table->date('reporting_date');
                    $table->text('criterion_id');
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('cost_micros');
                    $table->bigInteger('conversions')->default(0);
                    $table->decimal('cost_amount', 20, 6);
                    $table->char('currency', 3);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'customer_id', 'reporting_date', 'criterion_id'], 'google_ads_keyword_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_keyword_daily_asset_date_idx');
                });
            }
        }

        // google_ads_search_term_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('google_ads_search_term_daily')) {
                DB::statement('CREATE TABLE google_ads_search_term_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, customer_id text NOT NULL, reporting_date date NOT NULL, search_term text NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, cost_micros bigint NOT NULL, conversions bigint NOT NULL DEFAULT 0, cost_amount numeric(20,6) NOT NULL, currency char(3) NOT NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, customer_id, reporting_date, search_term)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS google_ads_search_term_daily_asset_date_idx ON google_ads_search_term_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('google_ads_search_term_daily')) {
                Schema::create('google_ads_search_term_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('customer_id');
                    $table->date('reporting_date');
                    $table->text('search_term');
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('cost_micros');
                    $table->bigInteger('conversions')->default(0);
                    $table->decimal('cost_amount', 20, 6);
                    $table->char('currency', 3);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'customer_id', 'reporting_date', 'search_term'], 'google_ads_search_term_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_search_term_daily_asset_date_idx');
                });
            }
        }

        // google_ads_landing_page_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('google_ads_landing_page_daily')) {
                DB::statement('CREATE TABLE google_ads_landing_page_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, customer_id text NOT NULL, reporting_date date NOT NULL, landing_page text NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, cost_micros bigint NOT NULL, conversions bigint NOT NULL DEFAULT 0, cost_amount numeric(20,6) NOT NULL, currency char(3) NOT NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, customer_id, reporting_date, landing_page)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS google_ads_landing_page_daily_asset_date_idx ON google_ads_landing_page_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('google_ads_landing_page_daily')) {
                Schema::create('google_ads_landing_page_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('customer_id');
                    $table->date('reporting_date');
                    $table->text('landing_page');
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('cost_micros');
                    $table->bigInteger('conversions')->default(0);
                    $table->decimal('cost_amount', 20, 6);
                    $table->char('currency', 3);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'customer_id', 'reporting_date', 'landing_page'], 'google_ads_landing_page_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_landing_page_daily_asset_date_idx');
                });
            }
        }

        // google_ads_conversion_action_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_conversion_action_snapshot')) {
            Schema::create('google_ads_conversion_action_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('conversion_action_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'conversion_action_id'], 'google_ads_conversion_action_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_conversion_action_snapshot_asset_idx');
            });
        }

        // google_ads_conversion_action_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('google_ads_conversion_action_daily')) {
                DB::statement('CREATE TABLE google_ads_conversion_action_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, customer_id text NOT NULL, reporting_date date NOT NULL, conversion_action_id text NOT NULL, conversions bigint NOT NULL DEFAULT 0, conversions_value numeric(20,6) NULL, all_conversions bigint NOT NULL DEFAULT 0, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, customer_id, reporting_date, conversion_action_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS google_ads_conversion_action_daily_asset_date_idx ON google_ads_conversion_action_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('google_ads_conversion_action_daily')) {
                Schema::create('google_ads_conversion_action_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('customer_id');
                    $table->date('reporting_date');
                    $table->text('conversion_action_id');
                    $table->bigInteger('conversions')->default(0);
                    $table->decimal('conversions_value', 20, 6)->nullable();
                    $table->bigInteger('all_conversions')->default(0);
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'customer_id', 'reporting_date', 'conversion_action_id'], 'google_ads_conversion_action_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'google_ads_conversion_action_daily_asset_date_idx');
                });
            }
        }

        // google_ads_campaign_budget_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_campaign_budget_snapshot')) {
            Schema::create('google_ads_campaign_budget_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('budget_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'budget_id'], 'google_ads_campaign_budget_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_campaign_budget_snapshot_asset_idx');
            });
        }

        // google_ads_asset_coverage_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('google_ads_asset_coverage_snapshot')) {
            Schema::create('google_ads_asset_coverage_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('customer_id');
                $table->text('asset_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'customer_id', 'asset_id'], 'google_ads_asset_coverage_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'google_ads_asset_coverage_snapshot_asset_idx');
            });
        }

        // meta_ad_account_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('meta_ad_account_snapshot')) {
            Schema::create('meta_ad_account_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('account_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'account_id'], 'meta_ad_account_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'meta_ad_account_snapshot_asset_idx');
            });
        }

        // meta_campaign_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('meta_campaign_snapshot')) {
            Schema::create('meta_campaign_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('account_id');
                $table->text('campaign_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'account_id', 'campaign_id'], 'meta_campaign_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'meta_campaign_snapshot_asset_idx');
            });
        }

        // meta_adset_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('meta_adset_snapshot')) {
            Schema::create('meta_adset_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('account_id');
                $table->text('adset_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'account_id', 'adset_id'], 'meta_adset_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'meta_adset_snapshot_asset_idx');
            });
        }

        // meta_creative_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('meta_creative_snapshot')) {
            Schema::create('meta_creative_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('account_id');
                $table->text('creative_id');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'account_id', 'creative_id'], 'meta_creative_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'meta_creative_snapshot_asset_idx');
            });
        }

        // meta_campaign_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('meta_campaign_daily')) {
                DB::statement('CREATE TABLE meta_campaign_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, account_id text NOT NULL, reporting_date date NOT NULL, campaign_id text NOT NULL, spend numeric(20,6) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, reach bigint NULL, frequency numeric(12,6) NULL, currency char(3) NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, account_id, reporting_date, campaign_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS meta_campaign_daily_asset_date_idx ON meta_campaign_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('meta_campaign_daily')) {
                Schema::create('meta_campaign_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('account_id');
                    $table->date('reporting_date');
                    $table->text('campaign_id');
                    $table->decimal('spend', 20, 6);
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('reach')->nullable();
                    $table->decimal('frequency', 12, 6)->nullable();
                    $table->char('currency', 3)->nullable();
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'campaign_id'], 'meta_campaign_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'meta_campaign_daily_asset_date_idx');
                });
            }
        }

        // meta_adset_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('meta_adset_daily')) {
                DB::statement('CREATE TABLE meta_adset_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, account_id text NOT NULL, reporting_date date NOT NULL, adset_id text NOT NULL, spend numeric(20,6) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, reach bigint NULL, currency char(3) NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, account_id, reporting_date, adset_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS meta_adset_daily_asset_date_idx ON meta_adset_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('meta_adset_daily')) {
                Schema::create('meta_adset_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('account_id');
                    $table->date('reporting_date');
                    $table->text('adset_id');
                    $table->decimal('spend', 20, 6);
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('reach')->nullable();
                    $table->char('currency', 3)->nullable();
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'adset_id'], 'meta_adset_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'meta_adset_daily_asset_date_idx');
                });
            }
        }

        // meta_ad_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('meta_ad_daily')) {
                DB::statement('CREATE TABLE meta_ad_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, account_id text NOT NULL, reporting_date date NOT NULL, ad_id text NOT NULL, spend numeric(20,6) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, reach bigint NULL, currency char(3) NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, account_id, reporting_date, ad_id)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS meta_ad_daily_asset_date_idx ON meta_ad_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('meta_ad_daily')) {
                Schema::create('meta_ad_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('account_id');
                    $table->date('reporting_date');
                    $table->text('ad_id');
                    $table->decimal('spend', 20, 6);
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('reach')->nullable();
                    $table->char('currency', 3)->nullable();
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'ad_id'], 'meta_ad_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'meta_ad_daily_asset_date_idx');
                });
            }
        }

        // meta_typed_action_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('meta_typed_action_daily')) {
                DB::statement('CREATE TABLE meta_typed_action_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, account_id text NOT NULL, reporting_date date NOT NULL, entity_level text NOT NULL, entity_id text NOT NULL, action_type text NOT NULL, action_value numeric(20,6) NOT NULL, currency char(3) NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, account_id, reporting_date, entity_level, entity_id, action_type)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS meta_typed_action_daily_asset_date_idx ON meta_typed_action_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('meta_typed_action_daily')) {
                Schema::create('meta_typed_action_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('account_id');
                    $table->date('reporting_date');
                    $table->text('entity_level');
                    $table->text('entity_id');
                    $table->text('action_type');
                    $table->decimal('action_value', 20, 6);
                    $table->char('currency', 3)->nullable();
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'entity_level', 'entity_id', 'action_type'], 'meta_typed_action_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'meta_typed_action_daily_asset_date_idx');
                });
            }
        }

        // meta_delivery_breakdown_daily | UPSERT_DAILY_FACT | RANGE_MONTHLY
        if ($driver === 'pgsql') {
            if (! Schema::hasTable('meta_delivery_breakdown_daily')) {
                DB::statement('CREATE TABLE meta_delivery_breakdown_daily (id bigserial NOT NULL, digital_asset_id bigint NULL, external_resource_id bigint NULL, account_id text NOT NULL, reporting_date date NOT NULL, entity_id text NOT NULL, breakdown_type text NOT NULL, breakdown_value text NOT NULL, spend numeric(20,6) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, reach bigint NULL, currency char(3) NULL, contract_version integer NOT NULL, last_collection_run_id bigint NULL, last_dataset_run_id bigint NULL, first_collected_at timestamptz NOT NULL, last_collected_at timestamptz NOT NULL, source_timezone text NULL, record_fingerprint char(64) NOT NULL, metadata jsonb NULL, created_at timestamptz NULL, updated_at timestamptz NULL, PRIMARY KEY (id, reporting_date), UNIQUE (digital_asset_id, account_id, reporting_date, entity_id, breakdown_type, breakdown_value)) PARTITION BY RANGE (reporting_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS meta_delivery_breakdown_daily_asset_date_idx ON meta_delivery_breakdown_daily (digital_asset_id, reporting_date)');
            }
        } else {
            if (! Schema::hasTable('meta_delivery_breakdown_daily')) {
                Schema::create('meta_delivery_breakdown_daily', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('digital_asset_id')->nullable();
                    $table->unsignedBigInteger('external_resource_id')->nullable();
                    $table->text('account_id');
                    $table->date('reporting_date');
                    $table->text('entity_id');
                    $table->text('breakdown_type');
                    $table->text('breakdown_value');
                    $table->decimal('spend', 20, 6);
                    $table->bigInteger('impressions')->default(0);
                    $table->bigInteger('clicks')->default(0);
                    $table->bigInteger('reach')->nullable();
                    $table->char('currency', 3)->nullable();
                    $table->integer('contract_version');
                    $table->unsignedBigInteger('last_collection_run_id')->nullable();
                    $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                    $table->timestampTz('first_collected_at');
                    $table->timestampTz('last_collected_at');
                    $table->text('source_timezone')->nullable();
                    $table->char('record_fingerprint', 64);
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'entity_id', 'breakdown_type', 'breakdown_value'], 'meta_delivery_breakdown_daily_nk_unique');
                    $table->index(['digital_asset_id', 'reporting_date'], 'meta_delivery_breakdown_daily_asset_date_idx');
                });
            }
        }

        // website_url | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_url')) {
            Schema::create('website_url', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('asset_id');
                $table->text('normalized_url');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'asset_id', 'normalized_url'], 'website_url_nk_unique');
                $table->index(['digital_asset_id'], 'website_url_asset_idx');
            });
        }

        // website_http_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_http_snapshot')) {
            Schema::create('website_http_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at'], 'website_http_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'website_http_snapshot_asset_idx');
            });
        }

        // website_metadata_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_metadata_snapshot')) {
            Schema::create('website_metadata_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at'], 'website_metadata_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'website_metadata_snapshot_asset_idx');
            });
        }

        // website_heading_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_heading_snapshot')) {
            Schema::create('website_heading_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at'], 'website_heading_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'website_heading_snapshot_asset_idx');
            });
        }

        // website_schema_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_schema_snapshot')) {
            Schema::create('website_schema_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at'], 'website_schema_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'website_schema_snapshot_asset_idx');
            });
        }

        // website_content_stats | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_content_stats')) {
            Schema::create('website_content_stats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at'], 'website_content_stats_nk_unique');
                $table->index(['digital_asset_id'], 'website_content_stats_asset_idx');
            });
        }

        // website_performance_measurement | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_performance_measurement')) {
            Schema::create('website_performance_measurement', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('url');
                $table->timestampTz('observed_at');
                $table->text('strategy');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'url', 'observed_at', 'strategy'], 'website_performance_measurement_nk_unique');
                $table->index(['digital_asset_id'], 'website_performance_measurement_asset_idx');
            });
        }

        // website_infra_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('website_infra_snapshot')) {
            Schema::create('website_infra_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('asset_id');
                $table->timestampTz('observed_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['digital_asset_id', 'asset_id', 'observed_at'], 'website_infra_snapshot_nk_unique');
                $table->index(['digital_asset_id'], 'website_infra_snapshot_asset_idx');
            });
        }

        // dataforseo_ranked_keyword_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('dataforseo_ranked_keyword_snapshot')) {
            Schema::create('dataforseo_ranked_keyword_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('target');
                $table->integer('location_code');
                $table->text('language_code');
                $table->text('keyword');
                $table->timestampTz('retrieved_at');
                $table->bigInteger('search_volume')->default(0);
                $table->decimal('etv', 20, 6);
                $table->char('currency', 3)->nullable();
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['target', 'location_code', 'language_code', 'keyword', 'retrieved_at'], 'dataforseo_ranked_keyword_snapshot_nk_unique');
            });
        }

        // dataforseo_keyword_site_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('dataforseo_keyword_site_snapshot')) {
            Schema::create('dataforseo_keyword_site_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('target');
                $table->integer('location_code');
                $table->text('language_code');
                $table->text('keyword');
                $table->timestampTz('retrieved_at');
                $table->bigInteger('search_volume')->default(0);
                $table->decimal('cpc', 20, 6)->nullable();
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['target', 'location_code', 'language_code', 'keyword', 'retrieved_at'], 'dataforseo_keyword_site_snapshot_nk_unique');
            });
        }

        // dataforseo_competitor_domain_snapshot | UPSERT_CURRENT_STATE | NONE
        if (! Schema::hasTable('dataforseo_competitor_domain_snapshot')) {
            Schema::create('dataforseo_competitor_domain_snapshot', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id')->nullable();
                $table->text('target');
                $table->integer('location_code');
                $table->text('language_code');
                $table->text('competitor_domain');
                $table->timestampTz('retrieved_at');
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['target', 'location_code', 'language_code', 'competitor_domain', 'retrieved_at'], 'dataforseo_competitor_domain_snapshot_nk_unique');
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_competitor_domain_snapshot');
        Schema::dropIfExists('dataforseo_keyword_site_snapshot');
        Schema::dropIfExists('dataforseo_ranked_keyword_snapshot');
        Schema::dropIfExists('website_infra_snapshot');
        Schema::dropIfExists('website_performance_measurement');
        Schema::dropIfExists('website_content_stats');
        Schema::dropIfExists('website_schema_snapshot');
        Schema::dropIfExists('website_heading_snapshot');
        Schema::dropIfExists('website_metadata_snapshot');
        Schema::dropIfExists('website_http_snapshot');
        Schema::dropIfExists('website_url');
        Schema::dropIfExists('meta_delivery_breakdown_daily');
        Schema::dropIfExists('meta_typed_action_daily');
        Schema::dropIfExists('meta_ad_daily');
        Schema::dropIfExists('meta_adset_daily');
        Schema::dropIfExists('meta_campaign_daily');
        Schema::dropIfExists('meta_creative_snapshot');
        Schema::dropIfExists('meta_adset_snapshot');
        Schema::dropIfExists('meta_campaign_snapshot');
        Schema::dropIfExists('meta_ad_account_snapshot');
        Schema::dropIfExists('google_ads_asset_coverage_snapshot');
        Schema::dropIfExists('google_ads_campaign_budget_snapshot');
        Schema::dropIfExists('google_ads_conversion_action_daily');
        Schema::dropIfExists('google_ads_conversion_action_snapshot');
        Schema::dropIfExists('google_ads_landing_page_daily');
        Schema::dropIfExists('google_ads_search_term_daily');
        Schema::dropIfExists('google_ads_keyword_daily');
        Schema::dropIfExists('google_ads_keyword_snapshot');
        Schema::dropIfExists('google_ads_ad_snapshot');
        Schema::dropIfExists('google_ads_ad_group_snapshot');
        Schema::dropIfExists('google_ads_campaign_daily');
        Schema::dropIfExists('google_ads_campaign_snapshot');
        Schema::dropIfExists('google_ads_account_daily');
        Schema::dropIfExists('google_ads_account_snapshot');
        Schema::dropIfExists('gsc_sitemap_snapshot');
        Schema::dropIfExists('gsc_url_inspection_snapshot');
        Schema::dropIfExists('gsc_search_appearance_daily');
        Schema::dropIfExists('gsc_device_daily');
        Schema::dropIfExists('gsc_country_daily');
        Schema::dropIfExists('gsc_query_page_daily');
        Schema::dropIfExists('gsc_page_daily');
        Schema::dropIfExists('gsc_query_daily');
        Schema::dropIfExists('gsc_property_daily');
        Schema::dropIfExists('ga4_device_daily');
        Schema::dropIfExists('ga4_event_landing_daily');
        Schema::dropIfExists('ga4_event_campaign_daily');
        Schema::dropIfExists('ga4_event_channel_daily');
        Schema::dropIfExists('ga4_event_daily');
        Schema::dropIfExists('ga4_landing_page_daily');
        Schema::dropIfExists('ga4_campaign_daily');
        Schema::dropIfExists('ga4_source_medium_daily');
        Schema::dropIfExists('ga4_acquisition_channel_daily');
        Schema::dropIfExists('ga4_property_daily');
        Schema::dropIfExists('ga4_property_metadata');
    }
};
