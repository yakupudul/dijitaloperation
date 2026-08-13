<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Activity')]
class ActivityIndex extends Component
{
    public string $actor = 'all';

    public string $status = 'all';

    public string $period = 'last_28';

    /**
     * @return array<string, string>
     */
    public static function periodOptions(): array
    {
        return [
            'last_7' => 'Last 7 days',
            'last_14' => 'Last 14 days',
            'last_28' => 'Last 28 days',
            'last_90' => 'Last 90 days',
        ];
    }

    public function setActor(string $actor): void
    {
        $this->actor = in_array($actor, ['all', 'system', 'human'], true) ? $actor : 'all';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::periodOptions())) {
            $this->period = $period;
        }
    }

    public function render(): View
    {
        $seed = GlobalOperatingFixtures::activityTimeline();
        $live = DemoState::activityEvents();
        $timeline = collect(array_merge($live, $seed));

        if ($this->actor !== 'all') {
            $timeline = $timeline->where('actor_kind', $this->actor);
        }

        if ($this->status !== 'all') {
            $timeline = $timeline->where('status', $this->status);
        }

        $maxAgeDays = match ($this->period) {
            'last_7' => 7,
            'last_14' => 14,
            'last_90' => 90,
            default => 28,
        };

        $timeline = $timeline->filter(function (array $event) use ($maxAgeDays): bool {
            return $this->eventWithinPeriod($event, $maxAgeDays);
        });

        return view('livewire.demo.operations.activity-index', [
            'timeline' => $timeline->values()->all(),
            'legacyRuns' => DemoState::all()['activity'] ?? [],
            'periodOptions' => self::periodOptions(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventWithinPeriod(array $event, int $maxAgeDays): bool
    {
        if (isset($event['occurred_at']) && is_string($event['occurred_at'])) {
            try {
                $at = CarbonImmutable::parse($event['occurred_at']);

                return $at->greaterThanOrEqualTo(now()->subDays($maxAgeDays));
            } catch (\Throwable) {
                // fall through to relative labels
            }
        }

        $when = strtolower((string) ($event['when'] ?? ''));

        $ageDays = match (true) {
            $when === 'today' || $when === 'now' => 0,
            $when === 'yesterday' => 1,
            preg_match('/^(\d+)\s+days?\s+ago$/', $when, $m) === 1 => (int) $m[1],
            default => 3,
        };

        return $ageDays <= $maxAgeDays;
    }
}
