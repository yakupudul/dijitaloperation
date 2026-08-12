<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Campaigns')]
class CampaignsPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $statusFilter = 'all';

    public string $objectiveFilter = 'all';

    public bool $expert = false;

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->mountPeriod();

        $status = DemoState::getFilter('meta_status');
        $objective = DemoState::getFilter('meta_objective');
        $this->statusFilter = is_string($status) && $status !== '' ? $status : 'all';
        $this->objectiveFilter = is_string($objective) && $objective !== '' ? $objective : 'all';
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        DemoState::setFilter('meta_status', $status === 'all' ? null : $status);
    }

    public function setObjectiveFilter(string $objective): void
    {
        $this->objectiveFilter = $objective;
        DemoState::setFilter('meta_objective', $objective === 'all' ? null : $objective);
    }

    public function toggleExpert(): void
    {
        $this->expert = ! $this->expert;
    }

    public function render(): View
    {
        $campaigns = DemoCatalog::filterMetaCampaigns(
            $this->period,
            $this->statusFilter === 'all' ? null : $this->statusFilter,
            $this->objectiveFilter === 'all' ? null : $this->objectiveFilter,
        );

        return view('livewire.demo.meta.campaigns', [
            'assetId' => $this->assetId,
            'campaigns' => $campaigns,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
