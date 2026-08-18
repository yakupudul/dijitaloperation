<?php

namespace App\Livewire\Demo\Sales;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectReportProjection;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\ProspectReportSnapshot;
use App\Services\Prospects\CreateProspectReportSnapshotService;
use App\Services\Prospects\ProspectReadService;
use App\Services\Prospects\ProspectReportPdfRenderer;
use App\Services\Prospects\ProspectReportShareService;
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

    public string $internal_notes = '';

    public string $report_locale = 'en';

    public ?string $shareUrl = null;

    public function mount(string $prospectId): void
    {
        abort_unless(ctype_digit($prospectId), 404);
        abort_if(Prospect::query()->find($prospectId) === null, 404);

        $this->prospectId = $prospectId;
        $this->normalizeTab();

        $prospect = Prospect::query()->findOrFail($prospectId);
        $this->status = $prospect->status->value;
        $this->identity_status = $prospect->identity_status->value;
        $this->report_locale = in_array(app()->getLocale(), ['en', 'tr'], true) ? app()->getLocale() : 'en';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (! in_array($this->tab, ['overview', 'research', 'intelligence', 'report', 'activity'], true)) {
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
            $this->status = $prospect->fresh()?->status->value ?? $this->status;

            DemoState::flash(__('operator.prospects.research_queued'));
            $this->tab = 'research';
        } finally {
            $this->researching = false;
        }
    }

    public function generateInternalReport(): void
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);
        app(CreateProspectReportSnapshotService::class)->generate(
            $prospect,
            ProspectReportProjection::Internal,
            auth()->user(),
            $this->report_locale,
            internalNotes: $this->internal_notes !== '' ? $this->internal_notes : null,
        );
        DemoState::flash(__('operator.prospects.reports.internal_generated'));
        $this->tab = 'report';
    }

    public function generateClientReport(): void
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);
        app(CreateProspectReportSnapshotService::class)->generate(
            $prospect,
            ProspectReportProjection::ClientShareable,
            auth()->user(),
            $this->report_locale,
        );
        DemoState::flash(__('operator.prospects.reports.client_generated'));
        $this->tab = 'report';
    }

    public function downloadSnapshot(string $snapshotId): mixed
    {
        $snapshot = ProspectReportSnapshot::query()
            ->where('prospect_id', $this->prospectId)
            ->findOrFail($snapshotId);
        $artifact = app(ProspectReportPdfRenderer::class)->generateArtifact($snapshot);

        return redirect()->route('operator.prospect.report.pdf', [
            'prospectId' => $this->prospectId,
            'artifactId' => $artifact->id,
        ]);
    }

    public function shareSnapshot(string $snapshotId): void
    {
        $snapshot = ProspectReportSnapshot::query()
            ->where('prospect_id', $this->prospectId)
            ->findOrFail($snapshotId);
        $result = app(ProspectReportShareService::class)->createGrant($snapshot, auth()->user());
        $this->shareUrl = $result['url'];
        DemoState::flash(__('operator.prospects.reports.share_created'));
        $this->tab = 'report';
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
