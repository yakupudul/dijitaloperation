<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $renames = [
            'ga4_source_medium_daily' => [
                'sessionsource' => 'sessionSource',
                'sessionmedium' => 'sessionMedium',
                'engagedsessions' => 'engagedSessions',
            ],
            'ga4_campaign_daily' => [
                'sessioncampaignname' => 'sessionCampaignName',
                'engagedsessions' => 'engagedSessions',
            ],
            'ga4_landing_page_daily' => [
                'landingpage' => 'landingPage',
                'engagedsessions' => 'engagedSessions',
            ],
            'ga4_event_daily' => [
                'eventname' => 'eventName',
                'eventcount' => 'eventCount',
            ],
            'ga4_event_channel_daily' => [
                'eventname' => 'eventName',
                'sessiondefaultchannelgroup' => 'sessionDefaultChannelGroup',
                'eventcount' => 'eventCount',
            ],
            'ga4_event_campaign_daily' => [
                'eventname' => 'eventName',
                'sessioncampaignname' => 'sessionCampaignName',
                'eventcount' => 'eventCount',
            ],
            'ga4_event_landing_daily' => [
                'eventname' => 'eventName',
                'landingpage' => 'landingPage',
                'eventcount' => 'eventCount',
            ],
        ];

        foreach ($renames as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $from => $to) {
                if (! $this->columnExists($table, $from) || $this->columnExists($table, $to)) {
                    continue;
                }

                DB::statement(sprintf('ALTER TABLE %s RENAME COLUMN %s TO "%s"', $table, $from, $to));
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $renames = [
            'ga4_source_medium_daily' => [
                'sessionSource' => 'sessionsource',
                'sessionMedium' => 'sessionmedium',
                'engagedSessions' => 'engagedsessions',
            ],
            'ga4_campaign_daily' => [
                'sessionCampaignName' => 'sessioncampaignname',
                'engagedSessions' => 'engagedsessions',
            ],
            'ga4_landing_page_daily' => [
                'landingPage' => 'landingpage',
                'engagedSessions' => 'engagedsessions',
            ],
            'ga4_event_daily' => [
                'eventName' => 'eventname',
                'eventCount' => 'eventcount',
            ],
            'ga4_event_channel_daily' => [
                'eventName' => 'eventname',
                'sessionDefaultChannelGroup' => 'sessiondefaultchannelgroup',
                'eventCount' => 'eventcount',
            ],
            'ga4_event_campaign_daily' => [
                'eventName' => 'eventname',
                'sessionCampaignName' => 'sessioncampaignname',
                'eventCount' => 'eventcount',
            ],
            'ga4_event_landing_daily' => [
                'eventName' => 'eventname',
                'landingPage' => 'landingpage',
                'eventCount' => 'eventcount',
            ],
        ];

        foreach ($renames as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $from => $to) {
                if (! $this->columnExists($table, $from) || $this->columnExists($table, $to)) {
                    continue;
                }

                DB::statement(sprintf('ALTER TABLE %s RENAME COLUMN "%s" TO %s', $table, $from, $to));
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }
};
