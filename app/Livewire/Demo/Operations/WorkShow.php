<?php

namespace App\Livewire\Demo\Operations;

use App\Models\Task;
use App\Services\Tasks\TaskLifecycleService;
use App\Services\Tasks\TaskReadService;
use App\Support\Demo\DemoState;
use App\Support\Work\WorkUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Work item')]
class WorkShow extends Component
{
    public string $workId = '';

    public string $type = WorkUrl::TYPE_TASK;

    public string $taskCreateNonce = '';

    public function mount(string $workId, string $type = WorkUrl::TYPE_TASK): void
    {
        abort_unless(WorkUrl::isType($type), 404);

        $this->workId = $workId;
        $this->type = $type;
        $this->taskCreateNonce = (string) Str::uuid();
    }

    public function startTask(): void
    {
        $task = $this->canonicalTask();
        if ($task === null) {
            return;
        }

        try {
            app(TaskLifecycleService::class)->start($task, auth()->user());
            DemoState::flash(__('operator.flash.task_started'));
        } catch (ValidationException $exception) {
            DemoState::flash(collect($exception->errors())->flatten()->first() ?? __('operator.work.not_found'));
        }
    }

    public function completeTask(): void
    {
        $task = $this->canonicalTask();
        if ($task === null) {
            return;
        }

        try {
            app(TaskLifecycleService::class)->complete($task, [], auth()->user());
            DemoState::flash(__('operator.flash.task_completed'));
        } catch (ValidationException $exception) {
            DemoState::flash(collect($exception->errors())->flatten()->first() ?? __('operator.work.not_found'));
        }
    }

    public function render(): View
    {
        return view('livewire.demo.operations.work-show', [
            'item' => $this->resolveTask(),
            'type' => $this->type,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTask(): ?array
    {
        if ($this->type !== WorkUrl::TYPE_TASK || ! ctype_digit($this->workId)) {
            return null;
        }

        return app(TaskReadService::class)->findPresentation((int) $this->workId);
    }

    private function canonicalTask(): ?Task
    {
        if ($this->type !== WorkUrl::TYPE_TASK || ! ctype_digit($this->workId)) {
            DemoState::flash(__('operator.work.not_found'));

            return null;
        }

        $task = Task::query()->find((int) $this->workId);
        if ($task === null) {
            DemoState::flash(__('operator.work.not_found'));
        }

        return $task;
    }
}
