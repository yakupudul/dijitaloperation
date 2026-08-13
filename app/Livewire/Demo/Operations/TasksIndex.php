<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Work')]
class TasksIndex extends Component
{
    #[Url(as: 'view', history: true)]
    public string $view = 'my';

    public string $status = 'all';

    public string $typeFilter = 'all';

    public function mount(): void
    {
        $allowed = ['my', 'all', 'tasks', 'client_requests', 'recurring_reviews', 'approvals', 'waiting_on_client', 'qa_required', 'completed', 'unassigned', 'overdue', 'due_today'];
        if (! in_array($this->view, $allowed, true)) {
            $this->view = 'my';
        }

        $status = DemoState::getFilter('task_status');
        if (is_string($status) && $status !== '') {
            $this->status = $status;
        }
    }

    public function setView(string $view): void
    {
        $allowed = ['my', 'all', 'tasks', 'client_requests', 'recurring_reviews', 'approvals', 'waiting_on_client', 'qa_required', 'completed', 'unassigned', 'overdue', 'due_today'];
        if (in_array($view, $allowed, true)) {
            $this->view = $view;
        }
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

    public function triageRequest(string $id): void
    {
        DemoState::setClientRequestStatus($id, 'triaged');
    }

    public function planRequest(string $id): void
    {
        DemoState::setClientRequestStatus($id, 'planned');
    }

    public function waitRequest(string $id): void
    {
        DemoState::setClientRequestStatus($id, 'waiting_on_client');
    }

    public function doneRequest(string $id): void
    {
        DemoState::setClientRequestStatus($id, 'done');
    }

    public function declineRequest(string $id): void
    {
        DemoState::setClientRequestStatus($id, 'declined');
    }

    public function createTaskFromRequest(string $id): void
    {
        DemoState::createTaskFromClientRequest($id);
    }

    public function completeReview(string $id, string $result): void
    {
        DemoState::completeRecurringReview($id, $result);
    }

    public function skipReview(string $id): void
    {
        DemoState::skipRecurringReview($id, 'Skipped by operator');
    }

    public function approveItem(string $id): void
    {
        DemoState::setApprovalState($id, 'approved');
    }

    public function approveQa(string $workId): void
    {
        DemoState::setQaState($workId, 'approved');
    }

    public function render(): View
    {
        $all = collect(AgencyExecutionFixtures::workItems());

        $rows = match ($this->view) {
            'my' => $all->filter(fn (array $row): bool => AgencyExecutionFixtures::isMine($row)),
            'tasks' => $all->where('type', 'task'),
            'client_requests' => $all->where('type', 'client_request'),
            'recurring_reviews' => $all->where('type', 'recurring_review'),
            'approvals' => $all->where('type', 'approval'),
            'waiting_on_client' => $all->filter(fn (array $row): bool => (bool) ($row['waiting_on_client'] ?? false)),
            'qa_required' => $all->filter(fn (array $row): bool => (bool) ($row['qa_required'] ?? false) && ($row['qa_status'] ?? '') !== 'approved'),
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

        $open = $all->reject(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done', 'declined', 'skipped'], true));

        $glance = [
            'due_today' => $open->where('due_key', 'today')->count(),
            'overdue' => $open->where('due_key', 'overdue')->count(),
            'waiting_on_client' => $open->where('waiting_on_client', true)->count(),
            'qa_required' => $open->filter(fn (array $row): bool => (bool) ($row['qa_required'] ?? false) && ($row['qa_status'] ?? '') !== 'approved')->count(),
        ];

        return view('livewire.demo.operations.tasks-index', [
            'workItems' => $rows->values()->all(),
            'glance' => $glance,
            'capacity' => AgencyExecutionFixtures::teamCapacity(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
