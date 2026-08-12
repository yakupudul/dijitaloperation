<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Customers')]
class CustomersIndex extends Component
{
    public bool $showAdd = false;

    public string $name = '';

    public string $industry = '';

    public string $hq = '';

    public function openAdd(): void
    {
        $this->showAdd = true;
        $this->resetValidation();
    }

    public function closeAdd(): void
    {
        $this->showAdd = false;
        $this->name = '';
        $this->industry = '';
        $this->hq = '';
    }

    public function saveCustomer(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:120',
            'industry' => 'nullable|string|max:80',
            'hq' => 'nullable|string|max:120',
        ]);

        DemoState::addCustomer([
            'id' => 'c-demo-'.substr(md5($this->name.microtime()), 0, 8),
            'name' => $this->name,
            'industry' => $this->industry !== '' ? $this->industry : 'General',
            'hq' => $this->hq !== '' ? $this->hq : '—',
            'brands_count' => 0,
            'open_issues' => 0,
            'open_tasks' => 0,
            'status' => 'active',
        ]);

        $this->closeAdd();
    }

    public function render(): View
    {
        $state = DemoState::all();

        return view('livewire.demo.portfolio.customers-index', [
            'customers' => $state['customers'],
            'seed' => DemoCatalog::customer(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
