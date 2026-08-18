<?php

namespace App\Livewire\Demo\Sales;

use App\Models\SalesIntentRadarRun;
use App\Models\SalesSearchProfile;
use App\Services\Sales\IntentQueryPlanner;
use App\Services\Sales\IntentRadarService;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Sales\IntentSearchConfig;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Search Profile')]
class SearchProfileShow extends Component
{
    public string $profileId = '';

    public bool $paid_consent = false;

    public bool $running = false;

    public function mount(string $profileId): void
    {
        abort_unless(ctype_digit($profileId), 404);
        abort_if(SalesSearchProfile::query()->find($profileId) === null, 404);
        $this->profileId = $profileId;
    }

    public function runSearch(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        try {
            $profile = SalesSearchProfile::query()->findOrFail($this->profileId);
            app(IntentRadarService::class)->run($profile, auth()->user(), $this->paid_consent);
            DemoState::flash(__('operator.sales_intent.run_started'));
        } finally {
            $this->running = false;
        }
    }

    public function render(): View
    {
        $profile = SalesSearchProfile::query()->with('owner')->findOrFail($this->profileId);
        $runs = SalesIntentRadarRun::query()
            ->where('sales_search_profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('livewire.demo.sales.search-profile-show', [
            'profile' => $profile,
            'serviceLabel' => AgencyServiceOptions::label($profile->service_definition_code),
            'queryPlan' => app(IntentQueryPlanner::class)->plan($profile),
            'runs' => $runs,
            'paidCallsEnabled' => IntentSearchConfig::paidCallsEnabled(),
            'fixturesEnabled' => IntentSearchConfig::fixturesEnabled(),
        ]);
    }
}
