<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Findings')]
class FindingsIndex extends Component
{
    public string $severity = 'all';

    public function setSeverity(string $severity): void
    {
        $this->severity = $severity;
    }

    public function render(): View
    {
        $rows = collect(DemoCatalog::findings());
        if ($this->severity !== 'all') {
            $rows = $rows->where('severity', $this->severity);
        }

        return view('livewire.demo.operations.findings-index', [
            'findings' => $rows->values()->all(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
