<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brand')]
class BrandShow extends Component
{
    public string $brand = DemoCatalog::BRAND_ID;

    #[Url]
    public string $tab = 'overview';

    public function mount(string $brand): void
    {
        $this->brand = $brand;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function runPublicResearch(): void
    {
        DemoState::startPublicResearch();
    }

    public function runAiBrief(): void
    {
        DemoState::showAiBrief();
    }

    public function render(): View
    {
        $state = DemoState::all();
        $brandRow = collect($state['brands'])->firstWhere('id', $this->brand) ?? DemoCatalog::brand();

        return view('livewire.demo.portfolio.brand-show', [
            'brandRow' => $brandRow,
            'assets' => DemoCatalog::assets(),
            'findings' => DemoCatalog::findings(),
            'recommendations' => $state['recommendations'],
            'tasks' => $state['tasks'],
            'attention' => DemoCatalog::brandAttention(),
            'timeline' => DemoCatalog::decisionTimeline(),
            'research' => $state['public_research'],
            'aiBrief' => $state['ai_brief_visible'] ? DemoCatalog::aiBrandBrief() : null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
