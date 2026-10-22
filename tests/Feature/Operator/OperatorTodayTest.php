<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\AiLiveIndicator;
use App\Support\Operator\OperatorClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Panel audit D8: "bugün" is one Istanbul day, not the UTC day that starts at 03:00 Istanbul time. */
final class OperatorTodayTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_is_the_istanbul_day_on_every_counter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 21:30:00', 'UTC')); // 00:30 on 22 October in Istanbul

        $this->assertSame('2026-10-22', OperatorClock::today()->toDateString());
        $this->assertSame('2026-10-21 21:00:00', OperatorClock::startOfToday()->utc()->format('Y-m-d H:i:s'));

        $done = fn (string $finishedUtc): int => DB::table('ai_live_operations')->insertGetId(['kind' => 'call', 'operation' => 'site.cluster_gaps',
            'label' => 'Küme eksikleri', 'agent' => 'ClusterGapsAgent', 'status' => 'done', 'started_at' => $finishedUtc, 'finished_at' => $finishedUtc]);
        $done('2026-10-21 21:10:00'); // 00:10 Istanbul: today
        $done('2026-10-21 20:30:00'); // 23:30 Istanbul yesterday: not today, although within 24 hours

        $this->assertSame(1, AiLiveIndicator::doneToday());
    }
}
