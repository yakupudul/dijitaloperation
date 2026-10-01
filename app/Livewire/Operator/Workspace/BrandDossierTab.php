<?php

namespace App\Livewire\Operator\Workspace;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandCare;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandGaps;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Marka dosyası" tab: Eksikler (what blocks the AI work, each fixed only on "Onayla ve yap"), the Marka bakım ajanı's last note (summary, questions, its open tasks, "Şimdi incele"), then the
 * short file every AI agent reads first (compiled without AI) with a "Yenile" button, and the operator's goals and
 * constraints — the only hand-written part.
 */
class BrandDossierTab extends Component
{
    #[Locked]
    public int $brandId;

    public string $goals = '';

    public string $constraints = '';

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
        app(BrandGaps::class)->sync($this->brand());
        ['goals' => $this->goals, 'constraints' => $this->constraints] = BrandDossier::notes($this->brand());
    }

    public function rebuild(BrandDossier $dossier, BrandGaps $gaps): void
    {
        $this->actor();
        $gaps->sync($this->brand());
        $dossier->build($this->brand());
        $this->message = 'Marka dosyası yenilendi.';
    }

    /** "Onayla ve yap": one gap's fix (inside MoxDOP only). */
    public function applyGap(int $suggestionId, BrandGaps $gaps): void
    {
        $actor = $this->actor();
        $suggestion = Suggestion::query()->where('brand_id', $this->brandId)->where('decision_key', BrandGaps::DECISION)->findOrFail($suggestionId);
        $this->message = $gaps->apply($suggestion, $actor);
        $this->dispatch('ai-live-refresh');
    }

    /** "Şimdi incele": one review now, even when nothing changed (queued; the note updates when it is done). */
    public function reviewNow(): void
    {
        $this->actor();
        $brand = $this->brand();
        if (! $brand->isOperational()) {
            $this->message = 'Marka aktif değil; bakım ajanı yalnız aktif markalarda çalışır.';

            return;
        }
        BrandCare::queue($brand, force: true);
        $this->dispatch('ai-live-refresh');
        $this->message = 'Bakım ajanı sıraya alındı; birkaç dakika içinde not burada.';
    }

    public function saveNotes(BrandDossier $dossier): void
    {
        $this->actor();
        $this->validate(['goals' => ['nullable', 'string', 'max:4000'], 'constraints' => ['nullable', 'string', 'max:4000']]);
        $brand = $this->brand();
        BrandDossier::saveNotes($brand, $this->goals, $this->constraints);
        $dossier->build($brand);
        $this->message = 'Notlar kaydedildi; dosya yenilendi.';
    }

    public function render(BrandDossier $dossier): View
    {
        $brand = $this->brand();

        return view('livewire.operator.workspace.brand-dossier-tab', [
            'dossier' => BrandDossier::stored($brand) ?? $dossier->build($brand),
            'operational' => $brand->isOperational(),
            'gaps' => Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', BrandGaps::DECISION)->actionable()
                ->orderBy('priority')->orderBy('id')->get(['id', 'title', 'reason', 'action']),
            'care' => BrandCare::stored($brand),
            'careTasks' => Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', BrandCare::DECISION)->actionable()
                ->orderBy('priority')->orderBy('id')->get(['id', 'title', 'reason', 'channel', 'priority']),
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
