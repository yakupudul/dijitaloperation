<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\ChannelPulse;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Kanal nabzı (yakup 2026-11-09): healthy means real messages keep arriving, not "no error". WhatsApp looked
 * connected for a day while nothing came in; the pulse must call that silent, and must stay quiet for channels too
 * sparse to know.
 */
final class ChannelPulseTest extends TestCase
{
    private function hourly(CarbonImmutable $from, int $count, int $everyHours): array
    {
        return array_map(fn (int $i): CarbonImmutable => $from->addHours($i * $everyHours), range(0, $count - 1));
    }

    public function test_a_busy_channel_that_stops_for_a_day_is_silent(): void
    {
        $start = CarbonImmutable::parse('2026-10-20 08:00:00');
        $times = $this->hourly($start, 200, 2); // every 2 hours until ~6 Nov
        $last = $times[count($times) - 1];

        $row = (new ChannelPulse)->measure('whatsapp', 'WhatsApp', $times, $last->addHours(26));

        $this->assertSame(ChannelPulse::SILENT, $row['state']);
        $this->assertSame((float) ChannelPulse::FLOOR_HOURS, $row['limit_hours']);
        $this->assertSame(200, $row['events_30d']);
    }

    public function test_a_normal_night_is_not_silent(): void
    {
        $times = $this->hourly(CarbonImmutable::parse('2026-10-20 08:00:00'), 200, 2);
        $last = $times[count($times) - 1];

        $row = (new ChannelPulse)->measure('whatsapp', 'WhatsApp', $times, $last->addHours(10));

        $this->assertSame(ChannelPulse::OK, $row['state']);
    }

    public function test_a_quiet_channel_uses_its_own_usual_gap_but_never_more_than_four_days(): void
    {
        $times = $this->hourly(CarbonImmutable::parse('2026-10-01 08:00:00'), 10, 30);
        $last = $times[count($times) - 1];
        $pulse = new ChannelPulse;

        $this->assertSame(60.0, $pulse->measure('x', 'X', $times, $last)['limit_hours']);
        $this->assertSame(ChannelPulse::OK, $pulse->measure('x', 'X', $times, $last->addHours(50))['state']);
        $this->assertSame(ChannelPulse::SILENT, $pulse->measure('x', 'X', $times, $last->addHours(61))['state']);

        $weekly = $this->hourly(CarbonImmutable::parse('2026-08-01 08:00:00'), 10, 24 * 6);
        $this->assertSame((float) ChannelPulse::CEILING_HOURS, $pulse->measure('y', 'Y', $weekly, $weekly[9])['limit_hours']);
    }

    public function test_too_little_traffic_is_unknown_not_silent(): void
    {
        $times = $this->hourly(CarbonImmutable::parse('2026-10-01 08:00:00'), 5, 24);

        $row = (new ChannelPulse)->measure('social:1', 'Instagram moximucom', $times, $times[4]->addDays(20));

        $this->assertSame(ChannelPulse::UNKNOWN, $row['state']);
        $this->assertNull($row['limit_hours']);
    }
}
