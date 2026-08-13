<?php

namespace App\Livewire\Demo;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Url(as: 'mode', history: true)]
    public string $mode = 'my_work';

    public function mount(): void
    {
        if (! in_array($this->mode, ['my_work', 'agency'], true)) {
            $this->mode = 'my_work';
        }
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['my_work', 'agency'], true)) {
            $this->mode = $mode;
        }
    }

    public function resetDemo(): void
    {
        DemoState::reset();
        DemoState::flash('Demo Mode reset to seed state.');
    }

    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'dashboard' => DemoCatalog::dashboard($this->mode),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
