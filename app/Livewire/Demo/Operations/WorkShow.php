<?php

namespace App\Livewire\Demo\Operations;

use App\Enums\ClientRequestStatus;
use App\Services\Approvals\ApprovalReadService;
use App\Services\Approvals\ApprovalUiActions;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientRequests\ClientRequestUiActions;
use App\Services\Qa\QaUiActions;
use App\Services\RecurringReviews\RecurringReviewUiActions;
use App\Services\Tasks\TaskReadService;
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
        if ($this->type !== 'recurring_review') {
            return;
        }

        $outcome = app(RecurringReviewUiActions::class)->completeReview(
            $this->workId,
            $result,
            auth()->user(),
            'rr-ui:'.$this->workId.':'.$result.':'.$this->taskCreateNonce,
        );
        DemoState::flash($outcome['message'] ?? '');
        if ($outcome['ok']) {
            $this->taskCreateNonce = (string) Str::uuid();
        }
    }

    public function skipReview(): void
    {
        if ($this->type !== 'recurring_review') {
            return;
        }

        $outcome = app(RecurringReviewUiActions::class)->skipReview(
            $this->workId,
            auth()->user(),
            'Skipped by operator',
        );
        DemoState::flash($outcome['message'] ?? '');
    }

    public function approve(): void
    {
        if ($this->type !== 'approval') {
            return;
        }

        $result = app(ApprovalUiActions::class)->approve($this->workId, auth()->user());
        DemoState::flash($result['message'] ?? '');
    }

    public function approveQa(): void
    {
        $taskId = $this->type === 'task' ? $this->workId : null;
        if ($taskId === null && $this->type === 'approval') {
            $item = app(ApprovalReadService::class)->findPresentation((int) $this->workId);
            $taskId = isset($item['task_id']) ? (string) $item['task_id'] : null;
        }
        if ($taskId === null || ! ctype_digit((string) $taskId)) {
            DemoState::flash('Task not found for QA.');

            return;
        }

        $result = app(QaUiActions::class)->approveQaForTask(
            $taskId,
            auth()->user(),
            'qa-approve:'.$taskId.':'.$this->taskCreateNonce,
        );
        DemoState::flash($result['message'] ?? '');
        if ($result['ok']) {
            $this->taskCreateNonce = (string) Str::uuid();
        }
    }

    public function render(): View
    {
        $item = $this->resolveItem();
        $playbookId = 'pb-weekly-gads';
        if ($this->type === 'recurring_review' && is_array($item)) {
            $playbookId = (string) ($item['playbook_stable_key'] ?? $item['playbook_id'] ?? $playbookId);
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
            'recurring_review' => $this->resolveRecurringReview(),
            'approval' => $this->resolveApproval(),
            'task' => $this->resolveTask(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRecurringReview(): ?array
    {
        return app(RecurringReviewUiActions::class)->findPresentation($this->workId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveApproval(): ?array
    {
        if (! ctype_digit($this->workId)) {
            return null;
        }

        return app(ApprovalReadService::class)->findPresentation((int) $this->workId);
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
