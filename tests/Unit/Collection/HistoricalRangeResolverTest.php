<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\HistoricalRangeResolver;
use App\Services\Collection\Support\CollectionClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricalRangeResolverTest extends TestCase
{
    #[Test]
    public function resolves_dataset_specific_histories_without_universal_window(): void
    {
        $clock = new CollectionClock(CarbonImmutable::parse('2026-08-13', 'UTC'));
        $resolver = new HistoricalRangeResolver($clock);

        $gsc = $resolver->resolve([
            'minimum_required' => 'provider_16m_available',
            'recommended_initial_backfill' => '180d_ui_minimum',
        ]);
        $ga4 = $resolver->resolve([
            'minimum_required' => '180d',
            'recommended_initial_backfill' => '180d',
        ]);
        $adsSnapshot = $resolver->resolve([
            'minimum_required' => 'current',
            'recommended_initial_backfill' => 'current',
        ]);

        $this->assertSame('historical', $gsc['kind']);
        $this->assertSame(180, $gsc['days']);
        $this->assertSame('2026-02-14', $gsc['start']);
        $this->assertSame('2026-08-12', $gsc['end']);

        $this->assertSame('historical', $ga4['kind']);
        $this->assertSame(180, $ga4['days']);

        $this->assertSame('snapshot', $adsSnapshot['kind']);
        $this->assertNull($adsSnapshot['start']);
        $this->assertNull($adsSnapshot['end']);
    }

    #[Test]
    public function respects_provider_ceiling_when_desired_exceeds_availability(): void
    {
        $clock = new CollectionClock(CarbonImmutable::parse('2026-08-13', 'UTC'));
        $resolver = new HistoricalRangeResolver($clock);

        $resolved = $resolver->resolve([
            'minimum_required' => 'provider_16m_available',
            'recommended_initial_backfill' => 'provider_16m_available',
        ]);

        $this->assertSame('historical', $resolved['kind']);
        $this->assertSame(486, $resolved['days']);
        $this->assertFalse($resolved['provider_limit_applied']);
    }

    #[Test]
    public function priority_only_datasets_are_not_daily_backfills(): void
    {
        $resolver = new HistoricalRangeResolver(new CollectionClock(CarbonImmutable::parse('2026-08-13')));

        $resolved = $resolver->resolve([
            'minimum_required' => 'priority_sampled',
            'recommended_initial_backfill' => 'priority_only',
        ]);

        $this->assertSame('priority_only', $resolved['kind']);
        $this->assertNull($resolved['start']);
    }
}
