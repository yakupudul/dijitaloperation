<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Work\WorkUrl;
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
            $this->redirect(WorkUrl::show(WorkUrl::TYPE_TASK, $taskId));

            return;
        }

        abort(404);
    }

    public function render(): View
    {
        abort(404);
    }
}
