<?php

namespace App\Livewire\Demo\Operations;

use App\Services\Advisor\AdvisorWorkQueue;
use App\Services\Operator\OperatorExecutionReadService;
use App\Services\Work\WorkReadService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Work')]
class TasksIndex extends Component
{
    /** Faz 11b: `advice` is the single work list's suggested part — SEO Görevleri and every advisor channel by priority. */
    public const array VIEWS = ['my', 'all', 'tasks', 'completed', 'unassigned', 'overdue', 'due_today', 'advice'];

    #[Url(as: 'view', history: true)]
    public string $view = 'my';

    public string $status = 'all';

    public string $typeFilter = 'all';

    public string $viewMode = 'list';

    public string $taskCreateNonce = '';

    public function mount(): void
    {
        $allowed = self::VIEWS;
        if (! in_array($this->view, $allowed, true)) {
            $this->view = 'my';
        }

        $status = DemoState::getFilter('task_status');
        if (is_string($status) && $status !== '') {
            $this->status = $status;
        }

        $this->taskCreateNonce = (string) Str::uuid();
    }

    public function setView(string $view): void
    {
        $allowed = self::VIEWS;
        if (in_array($view, $allowed, true)) {
            $this->view = $view;
        }
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['list', 'board'], true) ? $mode : 'list';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        DemoState::setFilter('task_status', $status === 'all' ? null : $status);
    }

    public function setTypeFilter(string $type): void
    {
        $this->typeFilter = $type;
    }

    public function render(): View
    {
        $all = collect(app(WorkReadService::class)->workItems());
        $execution = app(OperatorExecutionReadService::class);

        $rows = match ($this->view) {
            'my' => $all->filter(fn (array $row): bool => $execution->isMine($row)),
            'tasks' => $all->where('type', 'task'),
            'completed' => $all->filter(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done'], true)),
            'unassigned' => $all->filter(fn (array $row): bool => in_array($row['owner_id'] ?? null, [null, ''], true) || ($row['owner'] ?? '') === 'Unassigned'),
            'overdue' => $all->where('due_key', 'overdue'),
            'due_today' => $all->where('due_key', 'today'),
            default => $all,
        };

        if ($this->status !== 'all') {
            $rows = $rows->where('status', $this->status);
        }

        if ($this->typeFilter !== 'all') {
            $rows = $rows->where('type', $this->typeFilter);
        }

        $open = $all->reject(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done', 'declined', 'skipped', 'dismissed', 'resolved', 'cancelled'], true));

        $glance = [
            'due_today' => $open->where('due_key', 'today')->count(),
            'overdue' => $open->where('due_key', 'overdue')->count(),
        ];

        return view('livewire.demo.operations.tasks-index', [
            'workItems' => $rows->values()->all(),
            'glance' => $glance,
            'capacity' => $execution->teamCapacity($all->values()->all()),
            'viewMode' => $this->viewMode,
            'flash' => DemoState::pullFlash(),
            'advice' => $this->view === 'advice' ? app(AdvisorWorkQueue::class)->top(60, null, 10) : [],
        ]);
    }
}
