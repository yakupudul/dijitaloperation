<?php

namespace App\Livewire\Demo\Operations;

use App\Enums\ClientRequestStatus;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientRequests\ClientRequestUiActions;
use App\Services\Tasks\TaskReadService;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Work item')]
class WorkShow extends Component
{
    public string $workId = '';

    public string $type = 'client_request';

    public string $taskCreateNonce = '';

    public function mount(string $workId, ?string $type = null): void
    {
        $this->workId = $workId;
        $this->type = $type ?? 'client_request';
        $this->taskCreateNonce = (string) Str::uuid();
    }

    public function triage(): void
    {
        $this->mutateRequestStatus(ClientRequestStatus::Triaged);
    }

    public function plan(): void
    {
        $this->mutateRequestStatus(ClientRequestStatus::Planned);
    }

    public function waitOnClient(): void
    {
        $this->mutateRequestStatus(ClientRequestStatus::WaitingOnClient);
    }

    public function markDone(): void
    {
        $this->mutateRequestStatus(ClientRequestStatus::Done);
    }

    public function decline(): void
    {
        $this->mutateRequestStatus(ClientRequestStatus::Declined);
    }

    public function createTask(): void
    {
        if ($this->type !== 'client_request') {
            return;
        }

        $result = app(ClientRequestUiActions::class)->createTask(
            $this->workId,
            auth()->user(),
            'cr-task:'.$this->workId.':'.$this->taskCreateNonce,
        );
        DemoState::flash($result['message'] ?? '');
        if ($result['ok']) {
            $this->taskCreateNonce = (string) Str::uuid();
        }
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
        $playbookId = 'pb-weekly-gads';
        if ($this->type === 'recurring_review' && is_array($item) && ! empty($item['playbook_id'])) {
            $playbookId = (string) $item['playbook_id'];
        }

        return view('livewire.demo.operations.work-show', [
            'item' => $item,
            'type' => $this->type,
            'knowledgeContext' => ClientValueFixtures::workKnowledgeContext($playbookId),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveItem(): ?array
    {
        return match ($this->type) {
            'client_request' => $this->resolveClientRequest(),
            'recurring_review' => collect(DemoState::recurringReviewsWithState())->firstWhere('id', $this->workId),
            'approval' => collect(AgencyExecutionFixtures::approvalsWithState())->firstWhere('id', $this->workId),
            'task' => $this->resolveTask(),
            default => collect(AgencyExecutionFixtures::workItems())->firstWhere('id', $this->workId),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTask(): ?array
    {
        if (! ctype_digit($this->workId)) {
            return null;
        }

        return app(TaskReadService::class)->findPresentation((int) $this->workId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveClientRequest(): ?array
    {
        if (! ctype_digit($this->workId)) {
            return null;
        }

        return app(ClientRequestReadService::class)->findPresentation((int) $this->workId);
    }

    private function mutateRequestStatus(ClientRequestStatus $status): void
    {
        if ($this->type !== 'client_request') {
            return;
        }

        $result = app(ClientRequestUiActions::class)->changeStatus($this->workId, $status, auth()->user());
        DemoState::flash($result['message'] ?? '');
    }
}
