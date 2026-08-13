<?php

namespace App\Services\Collection\Monitoring;

use Carbon\CarbonInterface;

final class CollectionDurationPresenter
{
    public function elapsed(?CarbonInterface $startedAt, ?CarbonInterface $finishedAt = null, ?CarbonInterface $now = null): ?string
    {
        if ($startedAt === null) {
            return null;
        }

        $end = $finishedAt ?? ($now ?? now());
        $seconds = max(0, $startedAt->diffInSeconds($end));

        return $this->formatSeconds($seconds);
    }

    public function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $rem = $seconds % 60;
        if ($minutes < 60) {
            return $rem > 0 ? "{$minutes}m {$rem}s" : "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $minRem = $minutes % 60;

        return $minRem > 0 ? "{$hours}h {$minRem}m" : "{$hours}h";
    }
}
