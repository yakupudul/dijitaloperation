<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Task')]
class TaskShow extends Component
{
    public string $taskId = '';

    public function mount(string $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function setStatus(string $status): void
    {
        DemoState::setTaskStatus($this->taskId, $status);
    }

    public function render(): View
    {
        $task = collect(DemoState::all()['tasks'])->firstWhere('id', $this->taskId);
        if ($task === null) {
            $task = DemoCatalog::tasksSeed()[0];
            $task['id'] = $this->taskId;
        }

        return view('livewire.demo.operations.task-show', [
            'task' => $task,
            'timeline' => DemoCatalog::decisionTimeline(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
