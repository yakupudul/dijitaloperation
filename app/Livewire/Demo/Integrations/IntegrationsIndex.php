<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\DiscoveryCandidate;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Integrations')]
class IntegrationsIndex extends Component
{
    use WithPagination;

    #[Url]
    public ?int $discoveryBrand = null;

    public string $profileSearch = '';

    public function updatedProfileSearch(): void
    {
        $this->resetPage('profilesPage');
    }

    #[Url(as: 'section', history: true)]
    public string $section = '';

    public function mount(): void
    {
        if ($this->section === 'site_connectors') {
            $this->redirect(route('operator.integrations.site-connectors'), navigate: true);
        }
    }

    public function render(OperatorIntegrationsHubQuery $hub): View
    {
        return view('livewire.demo.integrations.integrations-index', [
            'groups' => $hub->groups(),
            'discoveredProfiles' => DiscoveryCandidate::query()->with(['brand', 'digitalAsset'])
                ->where('status', 'accepted')->where('target_field', 'social_links')
                ->where('support_json->application->state', 'integration_ready')
                ->whereHas('digitalAsset', fn ($query) => $query->whereColumn('digital_assets.brand_id', 'discovery_candidates.brand_id'))
                ->when($this->discoveryBrand, fn ($query) => $query->where('brand_id', $this->discoveryBrand))
                ->when(trim($this->profileSearch) !== '', fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('accepted_value', 'like', '%'.trim($this->profileSearch).'%')
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', '%'.trim($this->profileSearch).'%'))))
                ->latest('reviewed_at')->paginate(10, pageName: 'profilesPage'),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
