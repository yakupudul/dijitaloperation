<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Work item')]
class WorkShow extends Component
{
    public string $workId = '';

    public string $type = 'client_request';

    public function mount(string $workId, ?string $type = null): void
    {
        $this->workId = $workId;
        $this->type = $type ?? 'client_request';
    }

    public function triage(): void
    {
        DemoState::setClientRequestStatus($this->workId, 'triaged');
    }

    public function plan(): void
    {
        DemoState::setClientRequestStatus($this->workId, 'planned');
    }

    public function waitOnClient(): void
    {
        DemoState::setClientRequestStatus($this->workId, 'waiting_on_client');
    }

    public function markDone(): void
    {
        DemoState::setClientRequestStatus($this->workId, 'done');
    }

    public function decline(): void
    {
        DemoState::setClientRequestStatus($this->workId, 'declined');
    }

    public function createTask(): void
    {
        DemoState::createTaskFromClientRequest($this->workId);
    }

    public function completeReview(string $result): void
    {
        DemoState::completeRecurringReview($this->workId, $result);
    }

    public function skipReview(): void
    {
        DemoState::skipRecurringReview($this->workId, 'Skipped by operator');
    }

    public function approve(): void
    {
        DemoState::setApprovalState($this->workId, 'approved');
    }

    public function approveQa(): void
    {
        DemoState::setQaState($this->workId, 'approved');
    }

    public function render(): View
    {
        $item = $this->resolveItem();

        return view('livewire.demo.operations.work-show', [
            'item' => $item,
            'type' => $this->type,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveItem(): ?array
    {
        return match ($this->type) {
            'client_request' => DemoState::findClientRequest($this->workId),
            'recurring_review' => collect(DemoState::recurringReviewsWithState())->firstWhere('id', $this->workId),
            'approval' => collect(AgencyExecutionFixtures::approvalsWithState())->firstWhere('id', $this->workId),
            default => collect(AgencyExecutionFixtures::workItems())->firstWhere('id', $this->workId),
        };
    }
}
