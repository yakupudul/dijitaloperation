<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
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
            'custom' => 'Custom range',
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
        $timeline = collect(GlobalOperatingFixtures::activityTimeline());

        if ($this->actor !== 'all') {
            $timeline = $timeline->where('actor_kind', $this->actor);
        }

        if ($this->status !== 'all') {
            $timeline = $timeline->where('status', $this->status);
        }

        return view('livewire.demo.operations.activity-index', [
            'timeline' => $timeline->values()->all(),
            'legacyRuns' => DemoState::all()['activity'] ?? [],
            'periodOptions' => self::periodOptions(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
