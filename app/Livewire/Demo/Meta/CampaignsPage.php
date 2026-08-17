<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
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
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    public string $statusFilter = 'all';

    public string $objectiveFilter = 'all';

    public function mount(string $assetId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
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

    public function render(): View
    {
        $workspace = app(MetaAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
        $campaigns = collect($workspace['campaigns'] ?? []);

        if ($this->statusFilter !== 'all') {
            $needle = strtoupper($this->statusFilter);
            $campaigns = $campaigns->filter(
                static fn (array $row): bool => strtoupper((string) ($row['status'] ?? '')) === $needle
            );
        }

        if ($this->objectiveFilter !== 'all') {
            $needle = strtoupper($this->objectiveFilter);
            $campaigns = $campaigns->filter(
                static fn (array $row): bool => str_contains(strtoupper((string) ($row['objective_family'] ?? '')), $needle)
                    || str_contains(strtoupper((string) ($row['optimization'] ?? '')), $needle)
            );
        }

        return view('livewire.demo.meta.campaigns', [
            'assetId' => $this->assetId,
            'campaigns' => $campaigns->values()->all(),
            'identity' => $workspace['identity'] ?? [],
            'migrationMode' => $workspace['migration_mode'] ?? null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
