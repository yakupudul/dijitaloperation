<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brands')]
class BrandsIndex extends Component
{
    public bool $showAdd = false;

    public string $name = '';

    public string $location = '';

    public function openAdd(): void
    {
        $this->showAdd = true;
    }

    public function closeAdd(): void
    {
        $this->showAdd = false;
        $this->name = '';
        $this->location = '';
    }

    public function saveBrand(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:120',
            'location' => 'nullable|string|max:120',
        ]);

        DemoState::addBrand([
            'id' => 'b-demo-'.substr(md5($this->name.microtime()), 0, 8),
            'customer_id' => DemoCatalog::CUSTOMER_ID,
            'name' => $this->name,
            'industry' => 'Demo',
            'location' => $this->location !== '' ? $this->location : '—',
            'website' => 'https://example.demo',
            'health' => 'healthy',
            'health_label' => 'Healthy',
            'assets_count' => 0,
            'open_tasks' => 0,
            'summary' => [
                'media_spend' => 0,
                'platform_leads' => 0,
                'website_leads' => 0,
                'calls_messages' => 0,
                'currency' => 'TRY',
            ],
        ]);

        $this->closeAdd();
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.brands-index', [
            'brands' => DemoState::all()['brands'],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
