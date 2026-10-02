<?php

namespace Tests\Feature\Ga4;

use App\Services\DataPool\PartitionManager;
use App\Services\Ga4\WebsiteGa4AnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Key events (conversions) are split by channel / campaign / landing page; plain events are not counted. */
final class Ga4ConversionSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_key_events_are_attributed_to_their_sources(): void
    {
        $row = fn (array $values): array => $values + ['external_resource_id' => 7, 'property_id' => '123', 'reporting_date' => '2026-09-10',
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        foreach (['ga4_event_channel_daily'] as $table) {
            app(PartitionManager::class)->ensureRange($table, '2026-09-10', '2026-09-10');
        }
        DB::table('ga4_key_event_daily')->insert($row(['eventName' => 'form_submit', 'keyEvents' => 5, 'record_fingerprint' => str_repeat('a', 64)]));
        DB::table('ga4_event_channel_daily')->insert([
            $row(['eventName' => 'form_submit', 'sessionDefaultChannelGroup' => 'Organic Search', 'eventCount' => 4, 'record_fingerprint' => str_repeat('b', 64)]),
            $row(['eventName' => 'form_submit', 'sessionDefaultChannelGroup' => 'Paid Search', 'eventCount' => 1, 'record_fingerprint' => str_repeat('c', 64)]),
            $row(['eventName' => 'page_view', 'sessionDefaultChannelGroup' => 'Direct', 'eventCount' => 900, 'record_fingerprint' => str_repeat('d', 64)]),
        ]);

        $sources = (new ReflectionMethod(WebsiteGa4AnalysisService::class, 'conversionSources'))
            ->invoke(app(WebsiteGa4AnalysisService::class), 7, '123', '2026-09-01', '2026-09-30');

        $this->assertSame([['label' => 'Organic Search', 'events' => 4.0], ['label' => 'Paid Search', 'events' => 1.0]], $sources['channel']);
        $this->assertSame([], $sources['campaign']);
    }
}
