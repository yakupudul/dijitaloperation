<?php

namespace App\Livewire\Demo;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function resetDemo(): void
    {
        DemoState::reset();
        DemoState::flash('Demo Mode reset to seed state.');
    }

    public function render(): View
    {
        $state = DemoState::all();

        return view('livewire.demo.dashboard', [
            'brand' => DemoCatalog::brand(),
            'assets' => DemoCatalog::assets(),
            'findings' => DemoCatalog::findings(),
            'tasks' => $state['tasks'],
            'activity' => $state['activity'],
            'attention' => DemoCatalog::brandAttention(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
