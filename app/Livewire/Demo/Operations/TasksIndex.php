<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Tasks')]
class TasksIndex extends Component
{
    #[Url(as: 'view', history: true)]
    public string $view = 'my';

    public string $status = 'all';

    public string $viewMode = 'list';

    public function mount(): void
    {
        if (! in_array($this->view, ['my', 'all', 'overdue', 'due_today', 'blocked', 'awaiting_outcome', 'completed'], true)) {
            $this->view = 'my';
        }

        $status = DemoState::getFilter('task_status');
        if (is_string($status) && $status !== '') {
            $this->status = $status;
        }
    }

    public function setView(string $view): void
    {
        if (in_array($view, ['my', 'all', 'overdue', 'due_today', 'blocked', 'awaiting_outcome', 'completed'], true)) {
            $this->view = $view;
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
        $mineIds = ['u-ayse', 'Ayşe Demir', 'Ayşe Yılmaz'];

        $rows = match ($this->view) {
            'my' => $all->filter(fn (array $t): bool => in_array($t['assignee_id'] ?? '', ['u-ayse'], true)
                || in_array($t['owner'] ?? '', $mineIds, true)),
            'overdue' => $all->filter(fn (array $t): bool => ($t['status'] ?? '') !== 'completed'
                && in_array($t['due'] ?? '', ['Last week', 'Yesterday', 'Overdue'], true)),
            'due_today' => $all->filter(fn (array $t): bool => in_array($t['due'] ?? '', ['Today', 'Tomorrow', 'Friday'], true)
                && ($t['status'] ?? '') !== 'completed'),
            'blocked' => $all->where('status', 'blocked'),
            'awaiting_outcome' => $all->where('status', 'completed')->filter(
                fn (array $t): bool => ($t['outcome']['status'] ?? null) !== null || ($t['outcome'] ?? null) === null
            ),
            'completed' => $all->where('status', 'completed'),
            default => $all,
        };

        if ($this->status !== 'all') {
            $rows = $rows->where('status', $this->status);
        }

        $glance = [
            'overdue' => $all->filter(fn (array $t): bool => ($t['status'] ?? '') !== 'completed'
                && in_array($t['due'] ?? '', ['Last week', 'Yesterday', 'Overdue'], true))->count(),
            'due_today' => $all->filter(fn (array $t): bool => in_array($t['due'] ?? '', ['Today', 'Tomorrow', 'Friday'], true)
                && ($t['status'] ?? '') !== 'completed')->count(),
            'blocked' => $all->where('status', 'blocked')->count(),
            'awaiting' => $all->where('status', 'completed')->count(),
        ];

        $board = [
            'open' => $all->where('status', 'open')->values()->all(),
            'in_progress' => $all->where('status', 'in_progress')->values()->all(),
            'blocked' => $all->where('status', 'blocked')->values()->all(),
            'completed' => $all->where('status', 'completed')->values()->all(),
        ];

        return view('livewire.demo.operations.tasks-index', [
            'tasks' => $rows->values()->all(),
            'board' => $board,
            'glance' => $glance,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
