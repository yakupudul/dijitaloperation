<?php

namespace App\Livewire\Demo\Operations;

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

        if (ctype_digit($taskId)) {
            $this->redirect(route('demo.work.show', ['workId' => $taskId, 'type' => 'task']), navigate: true);

            return;
        }

        abort(404);
    }

    public function setStatus(string $status): void
    {
        DemoState::setTaskStatus($this->taskId, $status);
    }

    public function render(): View
    {
        abort(404);
    }
}
