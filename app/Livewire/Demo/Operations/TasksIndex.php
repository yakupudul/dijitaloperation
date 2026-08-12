<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Tasks')]
class TasksIndex extends Component
{
    public string $status = 'all';

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function render(): View
    {
        $rows = collect(DemoState::all()['tasks']);
        if ($this->status !== 'all') {
            $rows = $rows->where('status', $this->status);
        }

        return view('livewire.demo.operations.tasks-index', [
            'tasks' => $rows->values()->all(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
