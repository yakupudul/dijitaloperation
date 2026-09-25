<?php

namespace App\Livewire\Operator\Settings;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\Brain\ComplianceBrake;
use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPackRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Sektör paketleri: switch packs on/off and edit their rules (phrases, advice, severity, on/off);
 * add own rules. Changes to pack rules are kept (origin = operator). Admin edits; everyone can read.
 */
#[Layout('operator.layouts.app')]
#[Title('Sektör paketleri')]
final class SectorPacksPage extends Component
{
    public ?int $editingId = null;

    public string $editPatterns = '';

    public string $editMessage = '';

    public string $editSeverity = 'medium';

    public string $newPack = '';

    public string $newLabel = '';

    public string $newPatterns = '';

    public string $newMessage = '';

    public string $message = '';

    public function mount(SectorPackRegistry $packs): void
    {
        $packs->syncDefaults();
    }

    public function togglePack(string $packId, SectorPackRegistry $packs): void
    {
        $this->admin();
        abort_unless($packs->get($packId) !== null, 404);
        $packs->setEnabled($packId, ! $packs->isEnabled($packId), auth()->id());
    }

    public function toggleRule(int $id): void
    {
        $this->admin();
        $rule = ComplianceRule::query()->findOrFail($id);
        $rule->forceFill(['active' => ! $rule->active, 'origin' => 'operator'])->save();
    }

    public function edit(int $id): void
    {
        $rule = ComplianceRule::query()->findOrFail($id);
        $this->editingId = $rule->id;
        $this->editPatterns = implode("\n", (array) $rule->patterns);
        $this->editMessage = $rule->message;
        $this->editSeverity = $rule->severity;
    }

    public function save(): void
    {
        $this->admin();
        $this->validate(['editPatterns' => ['required', 'string', 'max:5000'], 'editMessage' => ['required', 'string', 'max:1000'], 'editSeverity' => ['required', 'in:high,medium,low']]);
        ComplianceRule::query()->findOrFail((int) $this->editingId)->forceFill([
            'patterns' => self::lines($this->editPatterns), 'message' => trim($this->editMessage), 'severity' => $this->editSeverity, 'origin' => 'operator',
        ])->save();
        $this->editingId = null;
        $this->message = 'Kural kaydedildi; bir sonraki taramada uygulanır.';
    }

    public function addRule(): void
    {
        $this->admin();
        $this->validate(['newPack' => ['required', 'string'], 'newLabel' => ['required', 'string', 'max:160'], 'newPatterns' => ['required', 'string', 'max:5000'], 'newMessage' => ['required', 'string', 'max:1000']]);
        ComplianceRule::query()->create([
            'pack_id' => $this->newPack, 'rule_key' => 'custom-'.now()->format('YmdHis'), 'kind' => ComplianceRuleKinds::FORBIDDEN,
            'label' => trim($this->newLabel), 'patterns' => self::lines($this->newPatterns), 'message' => trim($this->newMessage),
            'severity' => 'medium', 'applies_to' => ComplianceRuleKinds::TEXT_SOURCES, 'active' => true, 'origin' => 'operator',
        ]);
        $this->reset('newLabel', 'newPatterns', 'newMessage');
        $this->message = 'Yeni yasaklı ifade kuralı eklendi.';
    }

    /** Legal gate (Hizmet Beyni): record why a health brand may run paid ads (admin only). */
    public function setEligibility(int $brandId, string $basis): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        abort_unless(in_array($basis, ['', 'first_month', 'health_tourism_abroad', 'legal_opinion'], true), 422);
        Brand::query()->findOrFail($brandId);
        DB::table('brain_legal_eligibility')->updateOrInsert(['brand_id' => $brandId], [
            'paid_ads_allowed' => $basis !== '', 'basis' => $basis ?: null,
            'valid_until' => $basis === 'first_month' ? now()->addMonth()->toDateString() : null,
            'confirmed_by' => auth()->id(), 'confirmed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->message = $basis === '' ? 'Ücretli reklam uygunluğu kaldırıldı.' : 'Ücretli reklam uygunluğu kaydedildi.';
    }

    public function render(SectorPackRegistry $packs): View
    {
        $healthBrands = Brand::query()->orderBy('name')->get()->filter(fn (Brand $b): bool => collect($packs->forBrand($b))->contains(fn ($p): bool => $p->id() === 'health'))->values();

        return view('livewire.operator.settings.sector-packs', [
            'packs' => collect($packs->all())->map(fn ($pack): array => [
                'id' => $pack->id(), 'label' => $pack->label(), 'description' => $pack->description(),
                'sectors' => $pack->sectorCodes(), 'enabled' => $packs->isEnabled($pack->id()),
                'rules' => ComplianceRule::query()->where('pack_id', $pack->id())->orderBy('id')->get(),
            ])->values()->all(),
            'sources' => ComplianceRuleKinds::SOURCE_LABELS,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'gateOn' => (int) config('moxdop-brain.legal.health_paid_ads_gate', 0) === 1,
            'healthBrands' => $healthBrands,
            'eligibility' => DB::table('brain_legal_eligibility')->whereIn('brand_id', $healthBrands->pluck('id'))->get()->keyBy('brand_id'),
            'blockedTypes' => ComplianceBrake::BLOCKED,
        ]);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: []), fn (string $l): bool => $l !== '')));
    }

    private function admin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
