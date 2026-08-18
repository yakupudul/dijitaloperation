<?php

namespace App\Livewire\Demo\Sales;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Services\Prospects\ProspectReadService;
use App\Services\Prospects\ProspectResearchService;
use App\Services\Prospects\UpdateProspectService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Prospect')]
class ProspectShow extends Component
{
    public string $prospectId = '';

    #[Url(history: true)]
    public string $tab = 'overview';

    public string $status = '';

    public string $identity_status = '';

    public bool $researching = false;

    public function mount(string $prospectId): void
    {
        abort_unless(ctype_digit($prospectId), 404);
        abort_if(Prospect::query()->find($prospectId) === null, 404);

        $this->prospectId = $prospectId;
        $this->normalizeTab();

        $prospect = Prospect::query()->findOrFail($prospectId);
        $this->status = $prospect->status->value;
        $this->identity_status = $prospect->identity_status->value;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (! in_array($this->tab, ['overview', 'research', 'intelligence', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function updateStatus(): void
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);

        $validated = $this->validate([
            'status' => ['required', Rule::enum(ProspectStatus::class)],
            'identity_status' => ['required', Rule::enum(ProspectIdentityStatus::class)],
        ]);

        app(UpdateProspectService::class)->update($prospect, $validated, auth()->user());

        DemoState::flash(__('operator.prospects.status_saved'));
    }

    public function researchProspect(): void
    {
        if ($this->researching) {
            return;
        }

        $prospect = Prospect::query()->findOrFail($this->prospectId);
        $detail = app(ProspectReadService::class)->detail($prospect);

        if (! ($detail['prospect']['can_research'] ?? true)) {
            DemoState::flash(__('operator.prospects.research_in_progress'), 'warning');

            return;
        }

        $this->researching = true;

        try {
            $research = app(ProspectResearchService::class);
            $run = $research->queue($prospect, auth()->user());
            $research->execute($run->fresh(), auth()->user());

            DemoState::flash(__('operator.prospects.research_queued'));
            $this->tab = 'research';
        } finally {
            $this->researching = false;
        }
    }

    public function render(): View
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);
        $detail = app(ProspectReadService::class)->detail($prospect);

        return view('livewire.demo.sales.prospect-show', [
            'detail' => $detail,
            'statusOptions' => ProspectReadService::statusOptions(),
            'identityOptions' => ProspectReadService::identityOptions(),
        ]);
    }
}
