<?php

namespace App\Livewire\Operator\Work;

use App\Models\User;
use App\Services\Work\ContentWeekPlan;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Bu haftanın içerik planı (Aşama 8): Monday's proposals of every brand (or the chosen one) on İçerik, one row each
 * with its one-line reason; "Tümünü onayla" per brand or for all, "At" per row (its place is filled at once).
 */
final class ContentWeekPlanCard extends Component
{
    #[Reactive]
    public ?int $brandId = null;

    public string $message = '';

    public function approveAll(?int $brandId, ContentWeekPlan $plan): void
    {
        $this->act(fn (User $user): string => $plan->approve(null, $user, $brandId));
    }

    public function approveOne(int $id, ContentWeekPlan $plan): void
    {
        $this->act(fn (User $user): string => $plan->approve([$id], $user));
    }

    public function drop(int $id, ContentWeekPlan $plan): void
    {
        $this->act(fn (User $user): string => $plan->drop($id, $user));
    }

    public function render(ContentWeekPlan $plan): View
    {
        $rows = $plan->waiting($this->brandId);

        return view('livewire.operator.work.content-week-plan-card', [
            'brands' => $rows->groupBy('brand_id'),
            'total' => $rows->count(),
            'results' => $rows->isNotEmpty() ? $plan->results($this->brandId) : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    /** @param  callable(User): string  $step */
    private function act(callable $step): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasRole(Roles::ADMIN), 403);
        try {
            $this->message = $step($user);
        } catch (ValidationException $error) {
            $this->message = (string) collect($error->errors())->flatten()->first();
        }
    }
}
