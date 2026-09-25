<?php

namespace App\Livewire\Operator\Brain;

use App\Models\BrainProposal;
use App\Services\Brain\Proposals\ProposalService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Beyin › Onay kuyruğu: everything the system or AI prepared, reviewed in bulk. "AI ile hazırla" starts preparation
 * in the background; approving applies each selected proposal, rejecting keeps it from being offered again.
 */
#[Layout('operator.layouts.app')]
#[Title('Onay kuyruğu')]
final class ProposalsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $kind = '';

    #[Url]
    public string $status = BrainProposal::STATUS_PENDING;

    #[Url(as: 'q')]
    public string $search = '';

    /** Minimum confidence (0–100) for the list and for "approve all above". */
    #[Url]
    public int $min = 0;

    /** @var list<int> */
    public array $selected = [];

    public function updating(string $name): void
    {
        if (in_array($name, ['kind', 'status', 'search', 'min'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function prepare(string $kind, ProposalService $proposals): void
    {
        try {
            $proposals->queue($kind, auth()->user());
            DemoState::flash($proposals->kind($kind)->label().' hazırlanıyor. Bitince bu listede görünür; sayfayı yenileyebilirsin.');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'warning');
        }
    }

    /** @param  list<int>  $visibleIds */
    public function toggleAll(array $visibleIds): void
    {
        $visibleIds = array_map('intval', $visibleIds);
        $this->selected = array_values(array_intersect($this->selected, $visibleIds)) === $visibleIds && $visibleIds !== [] ? [] : $visibleIds;
    }

    public function approveSelected(ProposalService $proposals): void
    {
        $result = $proposals->approve(array_map('intval', $this->selected), auth()->user());
        $this->selected = [];
        DemoState::flash($result['applied'].' öneri uygulandı.'.($result['failed'] > 0 ? ' '.$result['failed'].' öneri uygulanamadı (satırda nedeni yazıyor).' : ''), $result['failed'] > 0 ? 'warning' : 'success');
    }

    public function rejectSelected(ProposalService $proposals): void
    {
        $count = $proposals->reject(array_map('intval', $this->selected), auth()->user());
        $this->selected = [];
        DemoState::flash($count.' öneri reddedildi; tekrar önerilmez.');
    }

    /** Approve every pending proposal of the current filter whose confidence is at least $min. */
    public function approveAboveMin(ProposalService $proposals): void
    {
        abort_if($this->min < 50, 422);
        $ids = $this->query()->where('status', BrainProposal::STATUS_PENDING)->pluck('id')->map('intval')->all();
        $result = $proposals->approve($ids, auth()->user());
        DemoState::flash($result['applied'].' öneri (güven ≥ %'.$this->min.') uygulandı.'.($result['failed'] > 0 ? ' '.$result['failed'].' tanesi uygulanamadı.' : ''), $result['failed'] > 0 ? 'warning' : 'success');
    }

    /** @return Builder<BrainProposal> */
    private function query(): Builder
    {
        return BrainProposal::query()
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->min > 0, fn ($q) => $q->where('confidence', '>=', $this->min / 100))
            ->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'));
    }

    public function render(ProposalService $proposals): View
    {
        $rows = $this->query()->with('brand:id,name')->orderByDesc('confidence')->orderByDesc('id')->paginate(50);
        $counts = BrainProposal::query()->where('status', BrainProposal::STATUS_PENDING)->groupBy('kind')->selectRaw('kind, count(*) as n')->pluck('n', 'kind');

        return view('livewire.operator.brain.proposals', [
            'rows' => $rows,
            'visibleIds' => $rows->getCollection()->where('status', BrainProposal::STATUS_PENDING)->pluck('id')->map('intval')->values()->all(),
            'kinds' => collect($proposals->kinds())->map(fn ($k): array => [
                'label' => $k->label(), 'ai' => $k->usesAi(), 'noun' => $k->resultNoun(), 'state' => $proposals->state($k->kind()), 'pending' => (int) ($counts[$k->kind()] ?? 0),
            ])->all(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
