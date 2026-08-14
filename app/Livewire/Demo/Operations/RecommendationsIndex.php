<?php

namespace App\Livewire\Demo\Operations;

use App\Models\Recommendation;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\Recommendations\UpdateRecommendation;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Production Recommendations decision inbox — backed by App\Models\Recommendation via
 * RecommendationReadService. No Demo fixtures: empty means no Recommendation rows exist.
 */
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
        $recommendation = $this->resolveRecommendation($id);
        if ($recommendation === null) {
            return;
        }

        app(UpdateRecommendation::class)->accept($recommendation, auth()->user());
        DemoState::flash('Recommendation accepted. Human decision recorded — no external write.');
    }

    public function reject(string $id): void
    {
        $recommendation = $this->resolveRecommendation($id);
        if ($recommendation === null) {
            return;
        }

        app(UpdateRecommendation::class)->dismiss($recommendation, auth()->user());
        DemoState::flash('Recommendation dismissed.', 'info');
    }

    /**
     * Defer is a review posture, not a persisted status: the canonical Recommendation
     * statuses are open/accepted/dismissed/converted, so the row stays open.
     */
    public function defer(string $id): void
    {
        if ($this->resolveRecommendation($id) === null) {
            return;
        }

        DemoState::flash('Recommendation deferred for now — it stays open and no Task was created.', 'info');
    }

    /**
     * Task creation from a Recommendation is owned by the Work alignment Prompt.
     * This action never creates a Task row.
     */
    public function createTask(string $id): void
    {
        if ($this->resolveRecommendation($id) === null) {
            return;
        }

        DemoState::flash('Work alignment is not wired yet — no Task was created from this Recommendation.', 'info');
    }

    private function resolveRecommendation(string $id): ?Recommendation
    {
        if (! ctype_digit($id)) {
            return null;
        }

        return Recommendation::query()->find((int) $id);
    }

    public function render(): View
    {
        return view('livewire.demo.operations.recommendations-index', [
            'recommendations' => app(RecommendationReadService::class)->forListPresentation(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
