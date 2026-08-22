<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->expandExistingTables();
        $this->createCentralTables();
        $this->createResourceNaturalKeys();
    }

    private function expandExistingTables(): void
    {
        $bigints = [
            'activeUsers', 'totalUsers', 'newUsers', 'sessions', 'engagedSessions',
            'screenPageViews', 'eventCount', 'scrolledUsers', 'transactions',
            'ecommercePurchases', 'totalPurchasers',
        ];
        $decimals = [
            'engagementRate', 'bounceRate', 'averageSessionDuration', 'sessionsPerUser',
            'screenPageViewsPerSession', 'screenPageViewsPerUser', 'eventsPerSession',
            'userEngagementDuration', 'keyEvents', 'conversions', 'sessionKeyEventRate',
            'userKeyEventRate', 'purchaseRevenue', 'totalRevenue',
        ];

        $this->addMetrics('ga4_property_daily', $bigints, $decimals);

        $sessionTables = [
            'ga4_acquisition_channel_daily',
            'ga4_source_medium_daily',
            'ga4_campaign_daily',
            'ga4_landing_page_daily',
            'ga4_device_daily',
        ];
        foreach ($sessionTables as $table) {
            $this->addMetrics(
                $table,
                ['sessions', 'engagedSessions', 'activeUsers', 'totalUsers', 'newUsers', 'screenPageViews', 'eventCount'],
                ['engagementRate', 'bounceRate', 'averageSessionDuration', 'keyEvents', 'sessionKeyEventRate', 'totalRevenue'],
            );
        }

        if (Schema::hasTable('ga4_campaign_daily')) {
            Schema::table('ga4_campaign_daily', function (Blueprint $table): void {
                if (! Schema::hasColumn('ga4_campaign_daily', 'sessionCampaignId')) {
                    $table->text('sessionCampaignId')->nullable();
                }
                if (! Schema::hasColumn('ga4_campaign_daily', 'sessionSource')) {
                    $table->text('sessionSource')->nullable();
                }
                if (! Schema::hasColumn('ga4_campaign_daily', 'sessionMedium')) {
                    $table->text('sessionMedium')->nullable();
                }
            });
        }

        if (Schema::hasTable('ga4_landing_page_daily')) {
            Schema::table('ga4_landing_page_daily', function (Blueprint $table): void {
                if (! Schema::hasColumn('ga4_landing_page_daily', 'landingPagePlusQueryString')) {
                    $table->text('landingPagePlusQueryString')->nullable();
                }
            });
        }

        $this->addMetrics(
            'ga4_event_daily',
            ['eventCount', 'activeUsers', 'totalUsers'],
            ['eventCountPerUser', 'eventValue', 'keyEvents'],
        );
    }

    private function addMetrics(string $tableName, array $bigints, array $decimals): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($bigints as $name) {
            if (! Schema::hasColumn($tableName, $name)) {
                Schema::table($tableName, function (Blueprint $table) use ($name): void {
                    $table->bigInteger($name)->nullable();
                });
            }
        }

        foreach ($decimals as $name) {
            if (! Schema::hasColumn($tableName, $name)) {
                Schema::table($tableName, function (Blueprint $table) use ($name): void {
                    $table->decimal($name, 20, 6)->nullable();
                });
            }
        }
    }

    private function createCentralTables(): void
    {
        $this->createDailyTable('ga4_first_user_acquisition_daily', function (Blueprint $table): void {
            $table->text('firstUserDefaultChannelGroup');
            $table->text('firstUserSource');
            $table->text('firstUserMedium');
            $table->bigInteger('newUsers')->nullable();
            $table->bigInteger('activeUsers')->nullable();
            $table->bigInteger('totalUsers')->nullable();
            $table->decimal('keyEvents', 20, 6)->nullable();
            $table->decimal('userKeyEventRate', 20, 6)->nullable();
            $table->decimal('totalRevenue', 20, 6)->nullable();
        }, ['external_resource_id', 'property_id', 'reporting_date', 'firstUserDefaultChannelGroup', 'firstUserSource', 'firstUserMedium']);

        $this->createDailyTable('ga4_page_content_daily', function (Blueprint $table): void {
            $table->text('pagePathPlusQueryString');
            $table->text('pageTitle');
            $table->text('hostName');
            $table->bigInteger('screenPageViews')->nullable();
            $table->bigInteger('activeUsers')->nullable();
            $table->bigInteger('totalUsers')->nullable();
            $table->bigInteger('eventCount')->nullable();
            $table->bigInteger('scrolledUsers')->nullable();
            $table->decimal('userEngagementDuration', 20, 6)->nullable();
            $table->decimal('keyEvents', 20, 6)->nullable();
        }, ['external_resource_id', 'property_id', 'reporting_date', 'pagePathPlusQueryString', 'pageTitle', 'hostName']);

        $this->createDailyTable('ga4_key_event_daily', function (Blueprint $table): void {
            $table->text('eventName');
            $table->bigInteger('activeUsers')->nullable();
            $table->bigInteger('totalUsers')->nullable();
            $table->decimal('keyEvents', 20, 6)->nullable();
            $table->decimal('sessionKeyEventRate', 20, 6)->nullable();
            $table->decimal('userKeyEventRate', 20, 6)->nullable();
        }, ['external_resource_id', 'property_id', 'reporting_date', 'eventName']);

        $this->createDailyTable('ga4_technology_daily', function (Blueprint $table): void {
            $table->text('deviceCategory');
            $table->text('browser');
            $table->text('operatingSystem');
            $this->sessionMetricColumns($table);
        }, ['external_resource_id', 'property_id', 'reporting_date', 'deviceCategory', 'browser', 'operatingSystem']);

        $this->createDailyTable('ga4_geo_country_daily', function (Blueprint $table): void {
            $table->text('country');
            $this->sessionMetricColumns($table);
        }, ['external_resource_id', 'property_id', 'reporting_date', 'country']);

        $this->createDailyTable('ga4_geo_region_daily', function (Blueprint $table): void {
            $table->text('country');
            $table->text('region');
            $this->sessionMetricColumns($table);
        }, ['external_resource_id', 'property_id', 'reporting_date', 'country', 'region']);

        $this->createDailyTable('ga4_geo_city_daily', function (Blueprint $table): void {
            $table->text('country');
            $table->text('region');
            $table->text('city');
            $this->sessionMetricColumns($table);
        }, ['external_resource_id', 'property_id', 'reporting_date', 'country', 'region', 'city']);

        $this->createDailyTable('ga4_hour_daily', function (Blueprint $table): void {
            $table->text('dayOfWeek');
            $table->text('hour');
            $table->bigInteger('sessions')->nullable();
            $table->bigInteger('activeUsers')->nullable();
            $table->bigInteger('engagedSessions')->nullable();
            $table->decimal('keyEvents', 20, 6)->nullable();
            $table->decimal('sessionKeyEventRate', 20, 6)->nullable();
        }, ['external_resource_id', 'property_id', 'reporting_date', 'dayOfWeek', 'hour']);

        $this->createDailyTable('ga4_ecommerce_item_daily', function (Blueprint $table): void {
            $table->text('itemId');
            $table->text('itemName');
            $table->text('itemCategory');
            $table->bigInteger('itemsViewed')->nullable();
            $table->bigInteger('itemsAddedToCart')->nullable();
            $table->bigInteger('itemsCheckedOut')->nullable();
            $table->bigInteger('itemsPurchased')->nullable();
            $table->decimal('itemRevenue', 20, 6)->nullable();
            $table->decimal('cartToViewRate', 20, 6)->nullable();
            $table->decimal('purchaseToViewRate', 20, 6)->nullable();
        }, ['external_resource_id', 'property_id', 'reporting_date', 'itemId', 'itemName', 'itemCategory']);
    }

    private function sessionMetricColumns(Blueprint $table): void
    {
        $table->bigInteger('sessions')->nullable();
        $table->bigInteger('engagedSessions')->nullable();
        $table->bigInteger('activeUsers')->nullable();
        $table->bigInteger('totalUsers')->nullable();
        $table->bigInteger('newUsers')->nullable();
        $table->bigInteger('screenPageViews')->nullable();
        $table->bigInteger('eventCount')->nullable();
        $table->decimal('engagementRate', 20, 6)->nullable();
        $table->decimal('bounceRate', 20, 6)->nullable();
        $table->decimal('averageSessionDuration', 20, 6)->nullable();
        $table->decimal('keyEvents', 20, 6)->nullable();
        $table->decimal('sessionKeyEventRate', 20, 6)->nullable();
        $table->decimal('totalRevenue', 20, 6)->nullable();
    }

    private function createDailyTable(string $name, callable $domainColumns, array $naturalKey): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($domainColumns, $naturalKey, $name): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedBigInteger('external_resource_id');
            $table->text('property_id');
            $table->date('reporting_date');
            $domainColumns($table);
            $table->integer('contract_version');
            $table->unsignedBigInteger('last_collection_run_id')->nullable();
            $table->unsignedBigInteger('last_dataset_run_id')->nullable();
            $table->timestampTz('first_collected_at');
            $table->timestampTz('last_collected_at');
            $table->text('source_timezone')->nullable();
            $table->char('record_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique($naturalKey, substr($name.'_resource_nk_unique', 0, 60));
            $table->index(['external_resource_id', 'reporting_date'], substr($name.'_resource_date_idx', 0, 60));
        });
    }

    private function createResourceNaturalKeys(): void
    {
        $indexes = [
            'ga4_property_metadata' => ['external_resource_id', 'property_id'],
            'ga4_property_daily' => ['external_resource_id', 'property_id', 'reporting_date'],
            'ga4_acquisition_channel_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionDefaultChannelGroup'],
            'ga4_source_medium_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionSource', 'sessionMedium'],
            'ga4_campaign_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionCampaignId', 'sessionCampaignName', 'sessionSource', 'sessionMedium'],
            'ga4_landing_page_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'landingPagePlusQueryString'],
            'ga4_event_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName'],
            'ga4_event_channel_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'sessionDefaultChannelGroup'],
            'ga4_event_campaign_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'sessionCampaignName'],
            'ga4_event_landing_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'landingPage'],
            'ga4_device_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'deviceCategory'],
        ];

        foreach ($indexes as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $index = substr($table.'_resource_nk_unique', 0, 60);
            if (DB::getDriverName() === 'pgsql') {
                $cols = implode(', ', array_map(fn (string $column): string => '"'.str_replace('"', '""', $column).'"', $columns));
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "'.$index.'" ON "'.$table.'" ('.$cols.')');
            } else {
                try {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $index));
                } catch (\Throwable) {
                    // Existing equivalent index on disposable/test databases is acceptable.
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'ga4_ecommerce_item_daily', 'ga4_hour_daily', 'ga4_geo_city_daily', 'ga4_geo_region_daily',
            'ga4_geo_country_daily', 'ga4_technology_daily', 'ga4_key_event_daily',
            'ga4_page_content_daily', 'ga4_first_user_acquisition_daily',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        // Existing GA4 columns/indexes are intentionally left in place on rollback to avoid data loss.
    }
};
