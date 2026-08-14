<?php

namespace App\Livewire\Demo\Operations;

use App\Models\Recommendation;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\Recommendations\UpdateRecommendation;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
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

    public string $taskCreateNonce = '';

    public function mount(): void
    {
        $this->taskCreateNonce = (string) Str::uuid();
    }

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
     * Explicit Recommendation → Task handoff via canonical CreateTask.
     */
    public function createTask(string $id): void
    {
        $recommendation = $this->resolveRecommendation($id);
        if ($recommendation === null) {
            DemoState::flash('Recommendation not found.', 'info');

            return;
        }

        $actor = auth()->user();
        $service = app(CreateTaskFromRecommendation::class);
        if (! $service->userCanConvert($actor)) {
            DemoState::flash('You are not allowed to create Tasks from Recommendations.', 'info');

            return;
        }

        try {
            $task = $service->create(
                $recommendation,
                [],
                $actor,
                'rec-task:'.$recommendation->id.':'.$this->taskCreateNonce,
            );
            $this->taskCreateNonce = (string) Str::uuid();
            DemoState::flash('Task #'.$task->id.' created from Recommendation. Recommendation status unchanged.');
        } catch (\Throwable $exception) {
            DemoState::flash($exception->getMessage(), 'info');
        }
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
