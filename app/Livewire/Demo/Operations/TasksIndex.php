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

    public string $viewMode = 'list';

    public function mount(): void
    {
        $status = DemoState::getFilter('task_status');
        if (is_string($status) && $status !== '') {
            $this->status = $status;
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        DemoState::setFilter('task_status', $status === 'all' ? null : $status);
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['list', 'board'], true) ? $mode : 'list';
    }

    public function render(): View
    {
        $all = collect(DemoState::all()['tasks']);
        $rows = $all;
        if ($this->status !== 'all') {
            $rows = $rows->where('status', $this->status);
        }

        $board = [
            'open' => $all->where('status', 'open')->values()->all(),
            'in_progress' => $all->where('status', 'in_progress')->values()->all(),
            'blocked' => $all->where('status', 'blocked')->values()->all(),
            'completed' => $all->where('status', 'completed')->values()->all(),
        ];

        return view('livewire.demo.operations.tasks-index', [
            'tasks' => $rows->values()->all(),
            'board' => $board,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
