<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Activity')]
class ActivityIndex extends Component
{
    public function render(): View
    {
        return view('livewire.demo.operations.activity-index', [
            'activity' => DemoState::all()['activity'],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
