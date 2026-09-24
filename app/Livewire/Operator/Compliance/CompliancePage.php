<?php

namespace App\Livewire\Operator\Compliance;

use App\Models\Brand;
use App\Models\ComplianceFinding;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPackRegistry;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Uyum: sector-pack findings across brands — what, where, the exact phrase and the rule's advice; the
 * operator fixes the content at the source or dismisses a finding with a note.
 */
#[Layout('operator.layouts.app')]
#[Title('Uyum')]
final class CompliancePage extends Component
{
    use WithPagination;

    #[Url]
    public string $brand = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $status = 'open';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['brand', 'source', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function dismiss(int $id, string $note = ''): void
    {
        ComplianceFinding::query()->findOrFail($id)->forceFill([
            'status' => ComplianceFinding::STATUS_DISMISSED, 'note' => mb_substr(trim($note), 0, 500) ?: null,
            'status_changed_by' => auth()->id(), 'resolved_at' => now(),
        ])->save();
    }

    public function reopen(int $id): void
    {
        ComplianceFinding::query()->findOrFail($id)->forceFill(['status' => ComplianceFinding::STATUS_OPEN, 'resolved_at' => null, 'status_changed_by' => auth()->id()])->save();
    }

    public function scanNow(ComplianceAuditor $auditor, SectorPackRegistry $packs): void
    {
        $brands = Brand::query()->when($this->brand !== '', fn ($q) => $q->whereKey((int) $this->brand))->get()
            ->filter(fn (Brand $b): bool => $packs->forBrand($b) !== []);
        $open = 0;
        foreach ($brands as $brand) {
            $open += $auditor->scan($brand)['open'];
        }
        $this->message = sprintf('%d marka tarandı; %d açık bulgu.', $brands->count(), $open);
    }

    public function render(SectorPackRegistry $packs): View
    {
        $findings = ComplianceFinding::query()->with(['rule', 'brand:id,name'])
            ->when($this->brand !== '', fn ($q) => $q->where('brand_id', (int) $this->brand))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByRaw("case (select severity from compliance_rules where compliance_rules.id = compliance_findings.compliance_rule_id) when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderByDesc('last_seen_at')->paginate(30);
        $brandIds = Brand::query()->with('sectors')->orderBy('name')->get()
            ->filter(fn (Brand $b): bool => $packs->forBrand($b) !== [])->pluck('name', 'id');

        return view('livewire.operator.compliance.compliance-page', [
            'findings' => $findings,
            'brands' => $brandIds,
            'sources' => ComplianceRuleKinds::SOURCE_LABELS,
            'hasPacks' => $packs->all() !== [],
        ]);
    }
}
