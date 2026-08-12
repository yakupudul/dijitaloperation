<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Recommendations')]
class RecommendationsIndex extends Component
{
    public ?string $expandedId = null;

    public function expand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function approve(string $id): void
    {
        DemoState::setRecommendationStatus($id, 'approved');
        DemoState::flash('Recommendation approved (Demo Mode).');
    }

    public function reject(string $id): void
    {
        DemoState::setRecommendationStatus($id, 'rejected');
        DemoState::flash('Recommendation rejected (Demo Mode).', 'info');
    }

    public function createTask(string $id): void
    {
        DemoState::createTaskFromRecommendation($id);
    }

    public function render(): View
    {
        return view('livewire.demo.operations.recommendations-index', [
            'recommendations' => DemoState::all()['recommendations'],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
