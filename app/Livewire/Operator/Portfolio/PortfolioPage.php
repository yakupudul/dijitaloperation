<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\BrandTarget;
use App\Models\User;
use App\Services\Goals\BrandTargets;
use App\Services\Operator\PortfolioReader;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portföy (Aşama 5): every brand in one list — goal and this month, four channels, eight weeks, decisions waiting —
 * the one that needs attention most on top. A brand's goal is approved or changed right in its row.
 */
#[Layout('operator.layouts.app')]
#[Title('Portföy')]
final class PortfolioPage extends Component
{
    public const array SORTS = ['dikkat' => 'Dikkat isteyen önce', 'amac' => 'Amaca en uzak önce', 'ad' => 'Ada göre'];

    #[Url(as: 'sira', except: 'dikkat')]
    public string $sort = 'dikkat';

    #[Url(as: 'marka')]
    public ?int $brand = null;

    /** Brand whose goal is being edited, and the number typed. */
    public ?int $editing = null;

    public string $goal = '';

    public string $message = '';

    public function edit(int $brandId): void
    {
        $this->authorizeAdmin();
        $target = BrandTarget::query()->where('brand_id', $brandId)->first();
        $this->editing = $brandId;
        $this->goal = (string) ($target?->monthly_leads ?? $target?->proposed_leads ?? '');
    }

    public function approve(int $brandId, BrandTargets $targets): void
    {
        $user = $this->authorizeAdmin();
        $target = BrandTarget::query()->where('brand_id', $brandId)->firstOrFail();
        $typed = trim($this->goal);
        try {
            $targets->approve($target, $user, $this->editing === $brandId && ctype_digit($typed) ? (int) $typed : null);
        } catch (ValidationException $error) {
            $this->message = (string) collect($error->errors())->flatten()->first();

            return;
        }
        $this->editing = null;
        $this->goal = '';
        $this->message = $target->brand?->name.' için amaç: ayda '.$target->fresh()->monthly_leads.' talep.';
        $this->dispatch('operator-notice', message: $this->message, tone: 'success');
    }

    public function render(PortfolioReader $reader): View
    {
        $rows = $reader->rows(isset(self::SORTS[$this->sort]) ? $this->sort : 'dikkat');
        $states = collect($rows)->countBy(fn (array $r): string => $r['progress']['state']);
        $withGoal = collect($rows)->filter(fn (array $r): bool => $r['progress']['goal'] !== null);

        return view('livewire.operator.portfolio.portfolio-page', [
            'rows' => $rows,
            'states' => $states,
            'goalTotal' => (int) $withGoal->sum(fn (array $r): int => (int) $r['progress']['goal']),
            'projectedTotal' => (int) $withGoal->sum(fn (array $r): int => (int) $r['progress']['projected']),
            'mtdTotal' => (int) $withGoal->sum(fn (array $r): int => (int) $r['progress']['mtd']),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function authorizeAdmin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasRole(Roles::ADMIN), 403);

        return $user;
    }
}
